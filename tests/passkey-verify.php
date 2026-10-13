<?php
/**
 * Passkey verification harness.
 *
 * Every fixture is a real WebAuthn payload with a real ECDSA signature, built
 * by Node's crypto (pk-fixture.js) rather than hand-written here. A test that
 * hand-builds its own fixtures can match a bug instead of the spec; these
 * cannot.
 */
chdir('C:/xampp/htdocs/ell');
require 'C:/xampp/htdocs/ell/app/bootstrap.php';

// The harness is not a web request, so give the RP helpers a host to work with.
$_SERVER['HTTP_HOST'] = 'localhost';
$_SERVER['HTTPS'] = '';

$fx = json_decode(file_get_contents('C:/Users/bappi/AppData/Local/Temp/opencode/pk-fixture.json'), true);
if (!is_array($fx)) { exit("fixture missing\n"); }

$pass = 0; $fail = 0;
function check(string $label, bool $ok, string $note = ''): void
{
    global $pass, $fail;
    if ($ok) { $pass++; echo "  PASS  $label\n"; }
    else { $fail++; echo "  FAIL  $label" . ($note !== '' ? " - $note" : '') . "\n"; }
}

echo "== registration: reading a real attestation ==\n";
$att = $fx['registration']['response'];
$cred = passkey_credential_from_attestation(['fmt' => 'none', 'attestationObject' => $att['attestationObject']]);
check('attestation accepted', $cred['ok'], $cred['reason'] ?? '');
check('algorithm detected as ES256', ($cred['alg'] ?? '') === 'ES256', 'got ' . ($cred['alg'] ?? 'none'));
check('credential id recovered', isset($cred['credential_id']) && b64url_encode($cred['credential_id']) === $fx['registration']['id']);
$pem = (string)($cred['pem'] ?? '');
check('public key is PEM', str_starts_with($pem, '-----BEGIN PUBLIC KEY-----'));
$p = openssl_pkey_get_public($pem);
check('OpenSSL parses the stored key', $p !== false, $p === false ? openssl_error_string() : '');
if ($p) {
    $d = openssl_pkey_get_details($p);
    check('key is EC on prime256v1', ($d['ec']['curve_name'] ?? '') === 'prime256v1');
}

echo "\n== refusal cases ==\n";
// Rewrite fmt inside the CBOR object to something we do not accept.
$rawObj = b64url_decode($att['attestationObject']);
$badObj = str_replace('none', 'pack', $rawObj, $n);
check('fixture rewrite happened', $n > 0);
$bad = passkey_credential_from_attestation(['attestationObject' => b64url_encode($badObj)]);
check('rejects a non-none attestation format', !$bad['ok'], 'was accepted');
check('non-none refusal names the format', str_contains((string)($bad['reason'] ?? ''), 'pack'));
$bad = passkey_credential_from_attestation(['fmt' => 'none', 'attestationObject' => 'bm90LWFuLWF0dGVzdGF0aW9u']);
check('rejects an unreadable attestation', !$bad['ok']);
$bad = passkey_credential_from_attestation([]);
check('rejects an empty attestation', !$bad['ok']);

echo "\n== assertion: verifying a real signature ==\n";
$stored = [
    'credential_id' => $fx['registration']['id'],
    'public_key' => $pem,
    'alg_id' => -7,
    'sign_counter' => 0,
    'admin_id' => 2,
];
$challenge = (string)$fx['challenge'];

$r = passkey_verify_assertion($stored, $fx['assertion1'], $challenge, 'http://localhost');
check('valid assertion accepted', $r['ok'], $r['reason'] ?? '');
check('counter read back', (int)($r['new_counter'] ?? -1) === 1, 'got ' . ($r['new_counter'] ?? 'none'));

$r = passkey_verify_assertion($stored, $fx['assertion2'], $challenge, 'http://localhost');
check('higher counter accepted', $r['ok'], $r['reason'] ?? '');

$stored['sign_counter'] = 5;
$r = passkey_verify_assertion($stored, $fx['assertion1'], $challenge, 'http://localhost');
check('counter going backwards refused', !$r['ok'], 'was accepted');

echo "\n== the checks that stop replay and phishing ==\n";
$r = passkey_verify_assertion($stored, $fx['assertionWrongOrigin'], $challenge, 'http://localhost');
check('wrong origin refused', !$r['ok'], 'was accepted');
$r = passkey_verify_assertion($stored, $fx['assertionWrongKey'], $challenge, 'http://localhost');
check('signature from another key refused', !$r['ok'], 'was accepted');

$r = passkey_verify_assertion($stored, $fx['assertion1'], 'some-other-challenge', 'http://localhost');
check('wrong challenge refused', !$r['ok'], 'was accepted');
$r = passkey_verify_assertion($stored, $fx['assertion1'], $challenge, 'https://evil.example');
check('server-side origin mismatch refused', !$r['ok'], 'was accepted');

// Tamper with the signature itself.
$tampered = $fx['assertion1'];
$raw = b64url_decode($tampered['response']['signature']);
$raw[20] = chr(ord($raw[20]) ^ 0x01);
$tampered['response']['signature'] = b64url_encode($raw);
$r = passkey_verify_assertion($stored, $tampered, $challenge, 'http://localhost');
check('tampered signature refused', !$r['ok'], 'was accepted');

// A credential id that is not the stored one.
$other = $fx['assertion1'];
$other['id'] = b64url_encode(str_repeat("\xAA", 16));
$r = passkey_verify_assertion($stored, $other, $challenge, 'http://localhost');
check('unknown credential id refused', !$r['ok'], 'was accepted');

// userHandle pointing at a different admin.
$wrongUser = $fx['assertion1'];
$wrongUser['response']['userHandle'] = b64url_encode('99');
$r = passkey_verify_assertion($stored, $wrongUser, $challenge, 'http://localhost');
check('userHandle for another account refused', !$r['ok'], 'was accepted');

// authData missing the UP flag: the device did not confirm a human.
$noUp = $fx['assertion1'];
$ad = b64url_decode($noUp['response']['authenticatorData']);
$ad[32] = chr(ord($ad[32]) & ~0x01 & 0xff);
$noUp['response']['authenticatorData'] = b64url_encode($ad);
$r = passkey_verify_assertion($stored, $noUp, $challenge, 'http://localhost');
check('missing user-presence flag refused', !$r['ok'], 'was accepted');

// authData with the AT flag set during a sign-in: unexpected data.
$at = $fx['assertion1'];
$ad2 = b64url_decode($at['response']['authenticatorData']);
$ad2[32] = chr(ord($at['response']['authenticatorData'] ? $ad2[32] : 0) | 0x80);
$at['response']['authenticatorData'] = b64url_encode($ad2);
$r = passkey_verify_assertion($stored, $at, $challenge, 'http://localhost');
check('AT flag on a sign-in refused', !$r['ok'], 'was accepted');

echo "\n== helpers ==\n";
check('b64url round trip', b64url_encode(b64url_decode('aGVsbG8td29ybGQ')) === 'aGVsbG8td29ybGQ');
check('sig->DER produces 70-72 bytes', in_array(strlen(passkey_sig_to_der(str_repeat("\x01", 64))), [70, 71, 72], true), 'got ' . strlen(passkey_sig_to_der(str_repeat("\x01", 64))));
check('sig->DER rejects wrong length', passkey_sig_to_der('short') === '');
check('rp id strips the port', (function () { $_SERVER['HTTP_HOST'] = 'localhost:8080'; $v = passkey_rp_id(); $_SERVER['HTTP_HOST'] = 'localhost'; return $v; })() === 'localhost');
check('rp id hash matches for this host', passkey_rp_id_hash_matches(hash('sha256', 'localhost', true)));
check('rp id hash rejects another host', !passkey_rp_id_hash_matches(hash('sha256', 'evil.example', true)));

// CBOR decoder against known values.
check('CBOR text string', passkey_cbor_decode(hex2bin('63616263')) === 'abc');
check('CBOR unsigned int', passkey_cbor_decode(hex2bin('1903e8')) === 1000);
check('CBOR negative int', passkey_cbor_decode(hex2bin('3903e7')) === -1000);
check('CBOR byte string', passkey_cbor_decode(hex2bin('43010203')) === "\x01\x02\x03");
// a2 = map(2) { "a": 1, "b": 2 }. The earlier fixture said a1 (map of ONE pair)
// and still expected two keys, so it was testing the wrong thing.
check('CBOR map of two pairs', json_encode(passkey_cbor_decode(hex2bin('a2616101616202'))) === '{"a":1,"b":2}', json_encode(passkey_cbor_decode(hex2bin('a2616101616202'))));
check('CBOR map of one pair', json_encode(passkey_cbor_decode(hex2bin('a161610161'))) === '{"a":1}', json_encode(passkey_cbor_decode(hex2bin('a161610161'))));
check('CBOR nested array', json_encode(passkey_cbor_decode(hex2bin('83010203'))) === '[1,2,3]', json_encode(passkey_cbor_decode(hex2bin('83010203'))));

echo "\n$pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);