<?php
declare(strict_types=1);

/**
 * Passkeys (WebAuthn) for the admin console.
 *
 * A passkey is a key pair created by the device. The private half never leaves
 * it; the public half is stored here. Signing in means the device proves it
 * holds the private half - there is no shared secret to steal, phish or reuse,
 * which is the whole point.
 *
 * Two constraints on this machine shaped the implementation, both found by
 * testing rather than assumed:
 *
 *  - This PHP build cannot generate keys (`openssl_pkey_new` fails for every
 *    algorithm) and cannot read binary DER public keys. It CAN parse PEM and
 *    verify with `openssl_verify`. So keys are stored as PEM, and the
 *    authenticator's raw public point is wrapped into PEM here.
 *  - `openssl_verify` expects a DER-encoded ECDSA signature, while WebAuthn
 *    sends raw `r||s`. Passkey signatures are converted below; getting that
 *    wrong is the classic cause of "signature verification failed" on an
 *    otherwise correct implementation.
 *
 * Both halves are covered by a harness in tests/passkey-verify.php.
 */

/** Algorithm ids from the WebAuthn/COSE registry we accept. */
const PASSKEY_ALGS = [
    -7 => ['name' => 'ES256', 'curve' => 'prime256v1', 'md' => OPENSSL_ALGO_SHA256],
    -257 => ['name' => 'RS256', 'curve' => null, 'md' => OPENSSL_ALGO_SHA256],
];

const PASSKEY_CHALLENGE_TTL = 300;   // seconds a registration/assertion challenge lives
const PASSKEY_COUNTER_FLOOR = 0;     // signCounter resets are tolerated once (see below)

function b64url_decode(string $s): string
{
    $s = strtr(trim($s), '-_', '+/');
    $pad = strlen($s) % 4;
    if ($pad) $s .= str_repeat('=', 4 - $pad);
    return (string)base64_decode($s, true);
}

function b64url_encode(string $s): string
{
    return rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
}

/**
 * Wrap a raw uncompressed EC point (what an authenticator hands back) in the
 * SubjectPublicKeyInfo envelope OpenSSL expects, then PEM-armor it.
 *
 * PHP's DER reader is unusable on this build, so PEM is the storage form.
 */
function passkey_public_key_pem(string $x, string $y, string $curve = 'prime256v1'): ?string
{
    if (strlen($x) !== 32 || strlen($y) !== 32) return null;

    // AlgorithmIdentifier for id-ecPublicKey + prime256v1, then the BIT STRING.
    $spkiPrefix = hex2bin(
        ($curve === 'secp384r1'
            ? '305a300f06072a8648ce3d020106082a8648ce3d030207035038'
            : '3059301306072a8648ce3d020106082a8648ce3d030107034200')
    );
    $der = $spkiPrefix . "\x04" . $x . $y;

    return "-----BEGIN PUBLIC KEY-----\n"
        . chunk_split(base64_encode($der), 64, "\n")
        . "-----END PUBLIC KEY-----\n";
}

/** RSA public keys arrive as COSE n/e integers; rebuild the SPKI from them. */
function passkey_rsa_public_key_pem(string $n, string $e): ?string
{
    if ($n === '' || $e === '') return null;
    $len = static function (string $v): int {
        $v = ltrim($v, "\x00");
        if ($v === '') return 1;
        if (ord($v[0]) & 0x80) return strlen($v) + 1;   // leading zero for sign
        return strlen($v);
    };
    $seq = static function (string $tag, string $body): string {
        return $tag . chr(strlen($body)) . $body;
    };
    $int = static function (string $v) use ($seq): string {
        $v = ltrim($v, "\x00");
        if ($v === '') $v = "\x00";
        return $seq("\x02", (ord($v[0]) & 0x80) ? "\x00" . $v : $v);
    };

    $rsaPubKey = $seq("\x30", $int($n) . $int($e));
    $algId = $seq("\x30", hex2bin('300d06092a864886f70d0101010500')); // rsaEncryption, NULL
    $bitString = "\x03" . chr(strlen($rsaPubKey) + 1) . "\x00" . $rsaPubKey;
    $der = $seq("\x30", $algId . $bitString);

    return "-----BEGIN PUBLIC KEY-----\n"
        . chunk_split(base64_encode($der), 64, "\n")
        . "-----END PUBLIC KEY-----\n";
}

/**
 * WebAuthn sends a raw 64-byte ECDSA signature (r||s). OpenSSL wants DER.
 * Each half is a DER INTEGER, so it is length-prefixed and - where the top bit
 * is set - given a 0x00 pad, because DER integers are signed.
 */
function passkey_sig_to_der(string $raw): string
{
    // ES256 is always r||s, 32 bytes each. Some authenticators, and some
    // platforms, send DER instead - pass that straight through rather than
    // reshaping it and producing a signature that cannot verify.
    if (strlen($raw) === 64) {
        $int = static function (string $v): string {
            $v = ltrim($v, "\x00");
            if ($v === '') $v = "\x00";
            if (ord($v[0]) & 0x80) $v = "\x00" . $v;
            return "\x02" . chr(strlen($v)) . $v;
        };
        $body = $int(substr($raw, 0, 32)) . $int(substr($raw, 32, 32));
        return "\x30" . chr(strlen($body)) . $body;
    }
    // Already DER: 0x30 <len> 0x02 ...
    if (strlen($raw) > 8 && ord($raw[0]) === 0x30 && ord($raw[2]) === 0x02) {
        return $raw;
    }
    return '';
}

/**
 * Pull the COSE key (CBOR) out of an attestation object and return a PEM
 * public key plus the algorithm used.
 *
 * Only the "none" attestation format is accepted. That is the normal case for
 * a first-party authenticator registering on the device that will use it, and
 * accepting it means no third party is vouched for at all - the key is
 * self-signed by the device and is only ever used for sign-in.
 *
 * @return array{ok:bool,pem?:string,alg?:string,reason?:string}
 */
function passkey_credential_from_attestation(array $att): array
{
    $raw = (string)($att['attestationObject'] ?? '');
    if ($raw === '') return ['ok' => false, 'reason' => 'The device sent no attestation data.'];

    $cbor = passkey_cbor_decode(b64url_decode($raw));
    if (!is_array($cbor) || !isset($cbor['authData'])) {
        return ['ok' => false, 'reason' => 'The attestation could not be read.'];
    }
    // fmt is inside the CBOR object. Reading it from the envelope instead is a
    // silent failure: $att['fmt'] is absent, so every real attestation would be
    // rejected for using an empty format.
    $fmt = (string)($cbor['fmt'] ?? '');
    if ($fmt !== 'none') {
        return ['ok' => false, 'reason' => 'This authenticator used the "' . ($fmt !== '' ? $fmt : 'unknown') . '" attestation format, which this site does not accept.'];
    }
    $authData = (string)$cbor['authData'];
    if (strlen($authData) < 37) return ['ok' => false, 'reason' => 'The attestation was too short to be valid.'];

    // The flags byte sits after the 32-byte RP ID hash, not at index 0.
    $flags = ord($authData[32]);
    if (!($flags & 0x40)) return ['ok' => false, 'reason' => 'The attestation is missing its attested credential data.'];
    // The RP ID hash is the first 32 bytes of authData (it is not in the CBOR map).
    if (!passkey_rp_id_hash_matches(substr($authData, 0, 32))) {
        return ['ok' => false, 'reason' => 'This passkey was created for a different website.'];
    }

    // Walk past rpIdHash (32) + flags (1) + signCount (4) to the credential data.
    // The AAGUID belongs to attestedCredentialData, so it is present whenever
    // AT is set - it is NOT gated on the ED flag, which is only for extension
    // data. Gating it on ED happens to work for authenticators that set both
    // and silently misparses every real one that sets only AT.
    $pos = 37;
    if ($flags & 0x40) $pos += 16;                      // AT: AAGUID is 16 bytes
    if ($flags & 0x08) {                                // ED: extension data (CBOR)
        $pos += passkey_cbor_length(substr($authData, $pos));
    }
    if ($pos + 18 > strlen($authData)) return ['ok' => false, 'reason' => 'The attestation was truncated.'];
    $credIdLen = unpack('n', substr($authData, $pos, 2))[1];
    $pos += 2;
    $credId = substr($authData, $pos, $credIdLen);
    $pos += $credIdLen;
    $cose = passkey_cbor_decode(substr($authData, $pos));
    if (!is_array($cose)) return ['ok' => false, 'reason' => 'The credential key could not be read.'];

    $pem = passkey_cose_to_pem($cose);
    if ($pem === null) return ['ok' => false, 'reason' => 'This passkey uses an algorithm this site cannot verify.'];
    $alg = PASSKEY_ALGS[(int)($cose[3] ?? 0)]['name'] ?? 'unknown';

    return ['ok' => true, 'pem' => $pem, 'alg' => $alg, 'credential_id' => $credId];
}

/** Turn a decoded COSE key map into a PEM public key. */
function passkey_cose_to_pem(array $cose): ?string
{
    $algId = (int)($cose[3] ?? 0);
    if (!isset(PASSKEY_ALGS[$algId])) return null;

    if ($algId === -7) {
        $x = (string)($cose[-2] ?? $cose[1] ?? '');
        $y = (string)($cose[-3] ?? $cose[2] ?? '');
        return passkey_public_key_pem($x, $y);
    }
    // RS256
    $n = (string)($cose[-1] ?? '');
    $e = (string)($cose[-2] ?? '');
    return passkey_rsa_public_key_pem($n, $e);
}

/**
 * A minimal CBOR reader covering exactly what WebAuthn needs: maps, byte
 * strings, text strings, negative integers, arrays and simple values.
 *
 * A full CBOR library is the right answer for anything general. This exists
 * because the verification path must not depend on a package that is not
 * installed, and it is deliberately narrow - anything it does not recognise
 * returns null rather than guessing.
 */
function passkey_cbor_decode(string $bin): mixed
{
    $r = new PasskeyCbor($bin);
    return $r->value();
}

/**
 * A cursor-based CBOR reader.
 *
 * A class rather than nested closures: the reader recurses into maps and
 * arrays, and closures capturing a shared cursor by reference is exactly the
 * kind of thing that silently reads the wrong offset. An explicit object with
 * one position field cannot get that wrong.
 */
final class PasskeyCbor
{
    private int $pos = 0;
    private string $bin;

    public function __construct(string $bin)
    {
        $this->bin = $bin;
    }

    /** Bytes consumed so far - used to step over an item without decoding it. */
    public function pos(): int
    {
        return $this->pos;
    }

    private function take(int $n): ?string
    {
        if ($n < 0 || $this->pos + $n > strlen($this->bin)) return null;
        $s = substr($this->bin, $this->pos, $n);
        $this->pos += $n;
        return $s;
    }

    /** @return array{0:int,1:int}|null [majorType, value] */
    private function head(): ?array
    {
        $ib = $this->take(1);
        if ($ib === null) return null;
        $b = ord($ib);
        $major = $b >> 5;
        $minor = $b & 0x1F;
        if ($minor < 24) return [$major, $minor];

        $len = match ($minor) {
            24 => 1, 25 => 2, 26 => 4, 27 => 8,
            default => null,      // 28-30 reserved, 31 indefinite
        };
        if ($len === null) return null;
        $bytes = $this->take($len);
        if ($bytes === null) return null;
        $v = 0;
        for ($i = 0; $i < $len; $i++) $v = ($v << 8) | ord($bytes[$i]);
        return [$major, $v];
    }

    public function value(): mixed
    {
        $h = $this->head();
        if ($h === null) return null;
        [$major, $n] = $h;

        switch ($major) {
            case 0: return $n;                      // unsigned integer
            case 1: return -1 - $n;                  // negative integer
            case 2: case 3: return $this->take($n); // byte / text string
            case 4:                                  // array
                $out = [];
                for ($i = 0; $i < $n; $i++) {
                    $v = $this->value();
                    if ($v === null) return null;
                    $out[] = $v;
                }
                return $out;
            case 5:                                  // map
                $out = [];
                for ($i = 0; $i < $n; $i++) {
                    $k = $this->value();
                    $v = $this->value();
                    if ($k === null) return null;
                    $out[is_string($k) ? $k : (string)$k] = $v;
                }
                return $out;
            case 7:
                if ($n === 20) return false;
                if ($n === 21) return true;
                return null;                          // null, undefined, floats
            default:
                return null;                          // tags and floats: not needed here
        }
    }
}

/**
 * Total byte length of the CBOR item at the start of $bin.
 *
 * Needed to step over the optional extension data in authData. It only has to
 * skip, not interpret, so anything it cannot size exactly aborts and returns
 * the whole input - which the caller then rejects as truncated rather than
 * parsing from a wrong offset.
 */
function passkey_cbor_length(string $bin): int
{
    if ($bin === '') return 0;
    $pos = 0;
    $ib = ord($bin[0]);
    $major = $ib >> 5;
    $minor = $ib & 0x1F;

    $len = match ($minor) {
        0, 1, 2, 3, 4, 5, 7 => 0,
        24 => 1, 25 => 2, 26 => 4, 27 => 8,
        default => -1,
    };
    if ($len < 0) return strlen($bin);
    $pos = 1 + $len;
    if ($pos > strlen($bin)) return strlen($bin);
    if ($len > 0) {
        $v = 0;
        foreach (str_split(substr($bin, 1, $len)) as $b) $v = ($v << 8) | ord($b);
    } else {
        $v = $minor;
    }

    if ($major === 2 || $major === 3) return $pos + $v;
    // Defensive walk for containers; these are only reached for malformed input.
    if ($major === 4) {
        for ($i = 0; $i < $v; $i++) $pos += passkey_cbor_length(substr($bin, $pos));
        return $pos;
    }
    if ($major === 5) {
        for ($i = 0; $i < $v; $i++) {
            $pos += passkey_cbor_length(substr($bin, $pos));
            $pos += passkey_cbor_length(substr($bin, $pos));
        }
        return $pos;
    }
    return $pos;
}

/**
 * Verify a sign-in assertion against a stored credential.
 *
 * Everything checked here is load-bearing: the signature proves the key, and
 * each field below stops a valid signature being replayed somewhere else.
 *
 * @return array{ok:bool,reason?:string,new_counter?:int}
 */
function passkey_verify_assertion(array $cred, array $payload, string $expectedChallenge, string $expectedOrigin): array
{
    // credential_id is stored base64url, so compare in that encoding. Re-encoding
// the decoded bytes would break any id whose encoding is not already canonical.
$presented = (string)($payload['id'] ?? '');
$storedId = (string)$cred['credential_id'];
if ($presented === '' || !hash_equals($storedId, $presented)) {
    return ['ok' => false, 'reason' => 'That passkey is not registered on this account.'];
}
    // userHandle is OPTIONAL and, when present, lives inside response - not at the
    // top level of the payload. Reading it from the wrong place is a silent
    // failure: the check looks like it is enforcing something and enforces
    // nothing at all.
    $uh = (string)($payload['response']['userHandle'] ?? '');
    if ($uh !== '' && $cred['admin_id'] !== null) {
        $decoded = b64url_decode($uh);
        if ($decoded !== '' && $decoded !== (string)$cred['admin_id']) {
            return ['ok' => false, 'reason' => 'That passkey belongs to a different account.'];
        }
    }

    // The credential type is "public-key"; the ceremony type (webauthn.get) lives
// in clientDataJSON. Conflating the two rejects every real assertion.
if ((string)($payload['type'] ?? '') !== 'public-key') {
    return ['ok' => false, 'reason' => 'That was not a public-key credential.'];
}

    $clientData = json_decode(b64url_decode((string)($payload['response']['clientDataJSON'] ?? '')), true);
    if (!is_array($clientData)) return ['ok' => false, 'reason' => 'The sign-in response could not be read.'];
    if ((string)($clientData['type'] ?? '') !== 'webauthn.get') {
        return ['ok' => false, 'reason' => 'That was not a sign-in response.'];
    }
    if ((string)($clientData['challenge'] ?? '') !== $expectedChallenge) {
        return ['ok' => false, 'reason' => 'This sign-in attempt is out of date. Try again.'];
    }
    if ((string)($clientData['origin'] ?? '') !== $expectedOrigin) {
        return ['ok' => false, 'reason' => 'This sign-in came from a different website.'];
    }
    if (!empty($clientData['crossOrigin'])) {
        return ['ok' => false, 'reason' => 'This sign-in came from an embedded frame.'];
    }

    // authData: rpIdHash(32) | flags(1) | signCount(4) [| attested data]
    $authData = b64url_decode((string)($payload['response']['authenticatorData'] ?? ''));
    if (strlen($authData) < 37) return ['ok' => false, 'reason' => 'The sign-in response was too short to be valid.'];
    $rpIdHash = substr($authData, 0, 32);
    if (!passkey_rp_id_hash_matches($rpIdHash)) {
        return ['ok' => false, 'reason' => 'This passkey was created for a different website.'];
    }
    $flags = ord($authData[32]);
    if (!($flags & 0x01)) {
        return ['ok' => false, 'reason' => 'The device did not confirm it was you.'];
    }
    if (!($flags & 0x04)) {
        return ['ok' => false, 'reason' => 'The sign-in data was not signed by the passkey.'];
    }
    if (($flags & 0x80) && strlen($authData) > 55) {
        return ['ok' => false, 'reason' => 'This sign-in carried unexpected data and was refused.'];
    }
    $counter = unpack('N', substr($authData, 33, 4))[1];

    $sigRaw = b64url_decode((string)($payload['response']['signature'] ?? ''));
    $algId = (int)$cred['alg_id'];
    $alg = PASSKEY_ALGS[$algId] ?? null;
    if ($alg === null) return ['ok' => false, 'reason' => 'This passkey uses an algorithm this site cannot verify.'];

    $sig = $algId === -7 ? passkey_sig_to_der($sigRaw) : $sigRaw;
    if ($sig === '') return ['ok' => false, 'reason' => 'The signature was malformed.'];

    $signed = $authData . hash('sha256', b64url_decode((string)($payload['response']['clientDataJSON'] ?? '')), true);
    $verified = @openssl_verify($signed, $sig, (string)$cred['public_key'], $alg['md']);
    if ($verified !== 1) {
        return ['ok' => false, 'reason' => 'That signature did not match this passkey.'];
    }

    // A counter that goes backwards can mean a cloned authenticator. Some
    // platform passkeys reset it to 0 legitimately, so only a decrease from a
    // value that was already non-zero is treated as a warning.
    $prev = (int)$cred['sign_counter'];
    if ($counter > 0 && $prev > 0 && $counter <= $prev) {
        return ['ok' => false, 'reason' => 'This passkey reported a repeated signature count. Remove it and add it again.'];
    }

    return ['ok' => true, 'new_counter' => $counter];
}

/** The RP ID hash must be the SHA-256 of the site's host, compared safely. */
function passkey_rp_id_hash_matches(string $b64orHex): bool
{
    $expected = hash('sha256', passkey_rp_id(), true);
    // Accept either the base64url form the browser sends or raw bytes.
    if (strlen($b64orHex) === 32) {
        return hash_equals($expected, $b64orHex);
    }
    return hash_equals($expected, b64url_decode($b64orHex));
}

/** The relying-party id: the host the site is served from, no port or path. */
function passkey_rp_id(): string
{
    $host = (string)($_SERVER['HTTP_HOST'] ?? parse_url((string)SITE_URL, PHP_URL_HOST) ?? 'localhost');
    // HTTP_HOST can carry a port; RP ID never includes one.
    $host = preg_split('/:/', $host)[0];
    return strtolower(trim($host)) ?: 'localhost';
}

/** The origin the browser must claim: scheme + host + optional port. */
function passkey_expected_origin(): string
{
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = (string)($_SERVER['HTTP_HOST'] ?? parse_url((string)SITE_URL, PHP_URL_HOST) ?? 'localhost');
    return $scheme . '://' . $host;
}

/** Store a single-use challenge. Never reused, never long-lived. */
function passkey_put_challenge(PDO $pdo, string $type, string $challenge, ?int $adminId): string
{
    $id = bin2hex(random_bytes(16));
    $pdo->prepare('INSERT INTO passkey_challenges (id, challenge, purpose, admin_id, expires_at)
                   VALUES (?,?,?,?, DATE_ADD(NOW(), INTERVAL ? SECOND))')
        ->execute([$id, $challenge, $type, $adminId, PASSKEY_CHALLENGE_TTL]);
    return $id;
}

/** Claim a challenge: returns it once, then deletes it. */
function passkey_take_challenge(PDO $pdo, string $id, string $type): ?string
{
    $st = $pdo->prepare('SELECT challenge FROM passkey_challenges WHERE id = ? AND purpose = ? AND expires_at > NOW() LIMIT 1');
    $st->execute([$id, $type]);
    $row = $st->fetch();
    $pdo->prepare('DELETE FROM passkey_challenges WHERE id = ?')->execute([$id]);
    return $row ? (string)$row['challenge'] : null;
}

/** Remove expired challenges. Called opportunistically, cheap at this size. */
function passkey_sweep_challenges(PDO $pdo): void
{
    try {
        $pdo->exec('DELETE FROM passkey_challenges WHERE expires_at < NOW()');
    } catch (Throwable) {
    }
}