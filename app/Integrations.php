<?php
declare(strict_types=1);

/**
 * The outside services this business runs on: card payments (Stripe), email
 * (PHPMailer over SMTP) and maps (Google).
 *
 * Credentials live in .env and nowhere else. The `settings` table stores only
 * non-secret state - what the last test said and when - so a database dump
 * can never leak a live key. That is why this writes to .env rather than
 * saving secrets through the normal settings form.
 *
 * Every provider is probed for real. A filled-in key is not a working
 * service, and this is the only place in the admin that can tell the
 * difference: each probe makes the actual network call the checkout, the
 * notification or the map would make.
 */
final class Integrations
{
    /**
     * What each integration needs, and what each field means.
     *
     * secret: true means the value is never sent back to the browser. The
     * field renders empty with a "saved" marker instead, and an empty
     * submission leaves the stored value alone.
     */
    public const SPEC = [
        'stripe' => [
            'label' => 'Stripe',
            'blurb' => 'Takes the card payment at checkout and confirms it by webhook.',
            'icon' => 'fa-brands fa-stripe',
            'fields' => [
                ['STRIPE_PUBLISHABLE_KEY', 'Publishable key', false, 'pk_live_…', 'Safe to show in page source. This one is meant to be public.'],
                ['STRIPE_SECRET_KEY', 'Secret key', true, 'sk_live_…', 'Charges cards and reads balances. Never leaves this server.'],
                ['STRIPE_WEBHOOK_SECRET', 'Webhook secret', true, 'whsec_…', 'Proves a payment update really came from Stripe. Without it, no card is ever confirmed.'],
            ],
        ],
        'mail' => [
            'label' => 'PHPMailer',
            'blurb' => 'Sends booking confirmations, receipts and trip updates.',
            'icon' => 'fa-solid fa-envelope',
            'fields' => [
                ['MAIL_HOST', 'SMTP host', false, 'smtp.gmail.com', 'The server that relays outgoing mail.'],
                ['MAIL_PORT', 'Port', false, '587', '587 is submission with STARTTLS. 465 is implicit TLS.'],
                ['MAIL_USERNAME', 'Username', false, 'bookings@yourdomain.com', 'Often the same as the from address.'],
                ['MAIL_PASSWORD', 'Password', true, '••••••••', 'App password or SMTP key, not your account password.'],
                ['MAIL_FROM_ADDRESS', 'From address', false, 'bookings@yourdomain.com', 'The address customers actually receive mail from.'],
                ['MAIL_FROM_NAME', 'From name', false, 'Exotic Lane Limo', 'Shown in the sender line.'],
            ],
        ],
        'maps' => [
            'label' => 'Google Maps',
            'blurb' => 'Turns an address into a pin, and draws the route on the booking form.',
            'icon' => 'fa-solid fa-map-location-dot',
            'fields' => [
                ['GOOGLE_MAPS_API_KEY', 'API key', true, 'AIza…', 'Restrict it to Maps JavaScript API and to your domain.'],
            ],
        ],
    ];

    /**
     * Marker meaning "this key should end up empty". Blank input cannot be
     * used for that, because a blank secret field must mean "unchanged".
     */
    public const CLEAR = '__clear__';

    /** Absolute path to .env, verified to be the real one inside the project. */
    public static function env_path(): string
    {
        $p = APP_ROOT . '/.env';
        $real = realpath($p);
        return ($real !== false && is_file($real)) ? $real : $p;
    }

    /** True when the server can actually be written to. Checked before offering the form. */
    public static function env_writable(): bool
    {
        $p = self::env_path();
        return is_file($p) && is_writable($p);
    }

    /**
     * Parse .env into key => value. Reads the file directly rather than $_ENV
     * so the admin sees what is actually on disk, not what this request
     * happened to load at bootstrap.
     */
    public static function env_read(): array
    {
        $out = [];
        $file = self::env_path();
        if (!is_file($file)) return $out;
        foreach (file($file, FILE_IGNORE_NEW_LINES) as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) continue;
            $pos = strpos($line, '=');
            if ($pos === false) continue;
            $k = trim(substr($line, 0, $pos));
            $v = trim(substr($line, $pos + 1));
            if (strlen($v) >= 2 && (($v[0] === '"' && $v[-1] === '"') || ($v[0] === "'" && $v[-1] === "'"))) {
                $v = substr($v, 1, -1);
            }
            $out[$k] = $v;
        }
        return $out;
    }

    /**
     * Replace named keys in .env, leaving every other line - comments, order,
     * unrelated keys - exactly as it was.
     *
     * Only keys that already exist in SPEC can be written, so this cannot be
     * used to inject an arbitrary variable. Values containing quotes or
     * newlines are refused rather than escaped, because load_env() strips
     * outer quotes without unescaping what is inside and a mangled secret is
     * worse than a rejected one.
     *
     * A blank value means "leave whatever is stored alone", which is what
     * lets a secret field render empty and still not erase the secret. To
     * actually clear a key, pass self::CLEAR for it.
     *
     * @param array<string,string> $pairs
     * @return array{ok:bool,error:?string,changed:array<int,string>,cleared:array<int,string>}
     */
    public static function env_write(array $pairs): array
    {
        $allowed = [];
        foreach (self::SPEC as $conf) {
            foreach ($conf['fields'] as $f) $allowed[$f[0]] = true;
        }

        $file = self::env_path();
        if (!is_file($file)) return ['ok' => false, 'error' => 'There is no .env file on this server.', 'changed' => [], 'cleared' => []];
        if (!is_writable($file)) return ['ok' => false, 'error' => 'The .env file is not writable by the web server, so keys cannot be saved here.', 'changed' => [], 'cleared' => []];

        $clean = [];
        $clear = [];
        foreach ($pairs as $k => $v) {
            if (!isset($allowed[$k])) continue;
            $v = trim((string)$v);
            if ($v === self::CLEAR) { $clear[$k] = true; continue; }
            if (preg_match('/["\'\r\n]/', $v)) {
                return ['ok' => false, 'error' => 'That value contains a quote or a line break, which cannot be stored safely.', 'changed' => [], 'cleared' => []];
            }
            if ($v === '') continue;
            $clean[$k] = $v;
        }
        if ($clean === [] && $clear === []) return ['ok' => true, 'error' => null, 'changed' => [], 'cleared' => []];

        $lines = file($file, FILE_IGNORE_NEW_LINES);
        if ($lines === false) return ['ok' => false, 'error' => 'The .env file could not be read.', 'changed' => [], 'cleared' => []];

        $changed = [];
        $cleared = [];
        foreach ($lines as $i => $line) {
            $trimmed = trim($line);
            if ($trimmed === '' || str_starts_with($trimmed, '#')) continue;
            $pos = strpos($trimmed, '=');
            if ($pos === false) continue;
            $k = trim(substr($trimmed, 0, $pos));
            if (isset($clear[$k])) {
                // Keep the key on file with an empty value rather than dropping
                // the line, so the operator can still see which key it was and
                // load_env() finds no stale value.
                $lines[$i] = $k . '=';
                $cleared[] = $k;
                unset($clear[$k]);
                continue;
            }
            if (!isset($clean[$k])) continue;
            $v = $clean[$k];
            // Quote a value containing spaces so load_env() reads it back whole.
            $lines[$i] = str_contains($v, ' ') ? $k . '="' . $v . '"' : $k . '=' . $v;
            $changed[] = $k;
            unset($clean[$k]);
        }

        // Anything the file did not already have is appended as a new line.
        foreach ($clean as $k => $v) {
            $lines[] = str_contains($v, ' ') ? $k . '="' . $v . '"' : $k . '=' . $v;
            $changed[] = $k;
        }

        // Atomic: write beside the file, then swap. A failure mid-write must
        // never leave a half-written .env where the site cannot boot.
        $eol = str_contains(implode('', array_slice($lines, 0, 20)), "\r\n") ? "\r\n" : "\n";
        $body = implode($eol, $lines) . $eol;
        $tmp = $file . '.tmp' . bin2hex(random_bytes(4));
        if (@file_put_contents($tmp, $body, LOCK_EX) === false) {
            return ['ok' => false, 'error' => 'The .env file could not be written.', 'changed' => [], 'cleared' => []];
        }
        if (!@rename($tmp, $file)) {
            @unlink($tmp);
            return ['ok' => false, 'error' => 'The .env file could not be replaced.', 'changed' => [], 'cleared' => []];
        }
        @chmod($file, 0644);
        sort($changed);
        sort($cleared);
        return ['ok' => true, 'error' => null, 'changed' => $changed, 'cleared' => $cleared];
    }

    /**
     * What the browser is allowed to see for one field.
     *
     * A secret never leaves the server. It comes back as an empty string plus
     * saved: true, so the admin sees that it is on file without the page
     * source carrying it.
     *
     * @return array{value:string,saved:bool,secret:bool}
     */
    public static function field_for_display(string $key, bool $secret, array $env): array
    {
        $stored = trim((string)($env[$key] ?? ''));
        if ($secret) return ['value' => '', 'saved' => $stored !== '', 'secret' => true];
        return ['value' => $stored, 'saved' => $stored !== '', 'secret' => false];
    }

    /**
     * Ask the provider whether it agrees with us.
     *
     * Returns state ok|bad|idle|off plus one plain sentence saying what
     * happened, written for the owner rather than for a log file.
     *
     * @param array<string,string> $vals
     * @return array{state:string,note:string}
     */
    public static function probe(string $slug, array $vals): array
    {
        return match ($slug) {
            'stripe' => self::probe_stripe($vals),
            'mail' => self::probe_mail($vals),
            'maps' => self::probe_maps($vals),
            default => ['state' => 'off', 'note' => 'Unknown integration.'],
        };
    }

    /** Stripe: ask the API for the live balance with the secret we hold. */
    private static function probe_stripe(array $v): array
    {
        $secret = trim((string)($v['STRIPE_SECRET_KEY'] ?? ''));
        if ($secret === '') {
            return ['state' => 'off', 'note' => 'No secret key yet. Add one to take card payments.'];
        }
        if (!preg_match('/^(sk|rk)_(test|live)_/', $secret)) {
            return ['state' => 'bad', 'note' => 'That is not a Stripe secret key. It should start with sk_ or rk_.'];
        }

        $ch = curl_init('https://api.stripe.com/v1/balance');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $secret],
            CURLOPT_TIMEOUT => 10,
            CURLOPT_CONNECTTIMEOUT => 6,
        ]);
        $body = (string)curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        if ($ch !== null && $code === 0) {
            return ['state' => 'bad', 'note' => 'No answer from api.stripe.com. Check this machine’s internet connection.'];
        }
        if ($code === 401) {
            return ['state' => 'bad', 'note' => 'Stripe rejected this key (401). It is wrong, revoked, or belongs to a different account.'];
        }
        if ($code !== 200) {
            return ['state' => 'bad', 'note' => 'Stripe answered ' . ($code ?: 'nothing') . '. ' . self::stripe_error($body)];
        }

        $mode = str_contains($secret, '_live_') ? 'Live mode' : 'Test mode';
        return ['state' => 'ok', 'note' => $mode . '. Stripe accepted the key and returned the balance.'];
    }

    private static function stripe_error(string $body): string
    {
        $j = json_decode($body, true);
        $msg = is_array($j) && isset($j['error']['message']) ? (string)$j['error']['message'] : '';
        return $msg !== '' ? 'It said: ' . $msg : 'The key was not accepted.';
    }

/**
     * Mail: open the SMTP connection, negotiate encryption the way the real
     * send path does, and complete the sign-in. This is a real handshake, not
     * a send - a test that emailed a customer would be a side effect nobody
     * asked for.
     *
     * STARTTLS is not optional. Gmail advertises no AUTH capability at all
     * until TLS is running, so a probe that skipped it would report a
     * perfectly good server as refusing the password.
     */
    private static function probe_mail(array $v): array
    {
        $host = trim((string)($v['MAIL_HOST'] ?? ''));
        $port = (int)($v['MAIL_PORT'] ?? 587);
        $user = trim((string)($v['MAIL_USERNAME'] ?? ''));
        $pass = (string)($v['MAIL_PASSWORD'] ?? '');
        $from = trim((string)($v['MAIL_FROM_ADDRESS'] ?? ''));

        if ($host === '') {
            return ['state' => 'off', 'note' => 'No SMTP host yet. Until there is one, nothing this site sends reaches anyone.'];
        }
        if ($port <= 0 || $port > 65535) {
            return ['state' => 'bad', 'note' => 'The port is not a valid port number. 587 is the usual choice.'];
        }
        if ($from === '' || !validate_email($from)) {
            return ['state' => 'bad', 'note' => 'The from address is missing or not a valid email address, so no message could be sent.'];
        }

        try {
            $smtp = new PHPMailer\PHPMailer\SMTP();
            $smtp->Timeout = 12;
            $smtp->SMTPKeepAlive = false;
            if (!$smtp->connect($host, $port, 12)) {
                return ['state' => 'bad', 'note' => 'Could not reach ' . $host . ' on port ' . $port . '. ' . self::smtp_reply($smtp)];
            }

            // connect() reads the greeting but the capability list has to be
            // requested explicitly before anything else can be attempted.
            $smtp->hello($host);
            $caps = self::smtp_caps($smtp);
            if ($caps === []) {
                return ['state' => 'bad', 'note' => 'The server at ' . $host . ' did not complete the SMTP greeting. ' . self::smtp_reply($smtp)];
            }

            if (isset($caps['STARTTLS'])) {
                $smtp->startTLS();
                $smtp->hello($host);
                $caps = self::smtp_caps($smtp);
            }

            if ($user !== '' && !isset($caps['AUTH'])) {
                $smtp->quit();
                return ['state' => 'bad', 'note' => 'The server answered but will not accept a sign-in on this port. ' . self::smtp_reply($smtp)];
            }

            if ($user !== '') {
                if (!$smtp->authenticate($user, $pass)) {
                    $why = self::smtp_reply($smtp);
                    $smtp->quit();
                    return ['state' => 'bad', 'note' => 'The server answered but refused the sign-in. ' . $why];
                }
            }
            $smtp->quit();
        } catch (Throwable $ex) {
            return ['state' => 'bad', 'note' => 'Could not talk to ' . $host . ' on port ' . $port . '. ' . self::short($ex->getMessage())];
        }

        return ['state' => 'ok', 'note' => 'Connected to ' . $host . ' and completed the SMTP handshake'
            . ($user !== '' ? ' with the sign-in.' : '. No username is set, so the server will only accept mail for addresses it already knows.')];
    }

    /** The server's advertised capabilities, or [] when the greeting failed. */
    private static function smtp_caps(PHPMailer\PHPMailer\SMTP $smtp): array
    {
        try {
            $r = new ReflectionProperty($smtp, 'server_caps');
            $r->setAccessible(true);
            $caps = $r->getValue($smtp);
            return is_array($caps) ? $caps : [];
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * The server's own last word. PHPMailer's getError() carries the SMTP
     * code in 'error' and the human line in 'detail'; the detail is often
     * empty, so the code and the raw reply are preferred when it is.
     */
    private static function smtp_reply(PHPMailer\PHPMailer\SMTP $smtp): string
    {
        $err = $smtp->getError();
        $code = trim((string)($err['smtp_code'] ?? ''));
        // A failed TCP connect leaves the code at 0, which is not a reply the
        // server ever gave and reads as nonsense in a sentence.
        if ($code === '0') $code = '';
        $detail = self::short((string)($err['detail'] ?? ''));
        if ($detail !== 'The connection failed without saying why.') return $detail;
        $raw = trim(preg_replace('/\s+/', ' ', $smtp->getLastReply()) ?? '');
        if ($code !== '' && $raw !== '') return 'The server replied ' . $code . ': ' . self::short($raw);
        if ($code !== '') return 'The server replied with code ' . $code . '.';
        if ($raw !== '') return 'The server said: ' . self::short($raw);
        return self::short((string)($err['error'] ?? ''));
    }

    /** Maps: geocode a real address and require a result, not just a 200. */
    private static function probe_maps(array $v): array
    {
        $key = trim((string)($v['GOOGLE_MAPS_API_KEY'] ?? ''));
        if ($key === '') {
            return ['state' => 'off', 'note' => 'No API key yet, so the booking form falls back to plain address fields.'];
        }

        $url = 'https://maps.googleapis.com/maps/api/geocode/json?address='
            . rawurlencode('233 S Wacker Dr, Chicago, IL') . '&key=' . rawurlencode($key);
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_CONNECTTIMEOUT => 6,
        ]);
        $body = (string)curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($code === 0) {
            return ['state' => 'bad', 'note' => 'No answer from maps.googleapis.com. Check this machine’s internet connection.'];
        }
        $j = json_decode($body, true);
        $status = is_array($j) && isset($j['status']) ? (string)$j['status'] : '';
        $results = is_array($j) && isset($j['results']) && is_array($j['results']) ? count($j['results']) : 0;

        if ($status === 'OK' && $results > 0) {
            return ['state' => 'ok', 'note' => 'The key works. Addresses on the booking form resolve to a pin.'];
        }
        if ($status === 'REQUEST_DENIED') {
            return ['state' => 'bad', 'note' => 'Google refused the request. The key is restricted, or the Maps JavaScript API is not enabled for it.'];
        }
        if ($status === 'ZERO_RESULTS') {
            return ['state' => 'bad', 'note' => 'Google answered but matched no address. The key may not have the Geocoding API enabled.'];
        }
        if ($status === 'INVALID_REQUEST') {
            return ['state' => 'bad', 'note' => 'Google says the request was malformed - usually a truncated or pasted-over key.'];
        }
        return ['state' => 'bad', 'note' => 'Google answered "' . ($status ?: 'unknown') . '" instead of a result.'];
    }

    /** Keep a provider's own wording short enough to sit in a sentence. */
    private static function short(string $msg): string
    {
        $msg = trim(preg_replace('/\s+/', ' ', $msg) ?? '');
        if ($msg === '') return 'The connection failed without saying why.';
        return strlen($msg) > 130 ? rtrim(substr($msg, 0, 127)) . '…' : (str_ends_with($msg, '.') ? $msg : $msg . '.');
    }

    /** Store the last test result. Never stores a credential. */
    public static function remember(PDO $pdo, string $slug, string $state, string $note): void
    {
        foreach ([
            'int_' . $slug . '_state' => $state,
            'int_' . $slug . '_note' => substr($note, 0, 255),
            'int_' . $slug . '_at' => date('Y-m-d H:i:s'),
        ] as $k => $v) {
            $pdo->prepare('INSERT INTO settings (skey, svalue) VALUES (?,?) ON DUPLICATE KEY UPDATE svalue=VALUES(svalue), updated_at=NOW()')->execute([$k, $v]);
        }
    }

    /**
     * The last stored test, plus whether that answer is still worth trusting.
     * A green result from four days ago is not a green result now.
     *
     * @return array{state:string,note:string,at:string,stale:bool,age:?string}
     */
    public static function last_result(PDO $pdo, string $slug): array
    {
        $state = (string)setting($pdo, 'int_' . $slug . '_state', '');
        $note = (string)setting($pdo, 'int_' . $slug . '_note', '');
        $at = (string)setting($pdo, 'int_' . $slug . '_at', '');
        if ($state === '') return ['state' => 'idle', 'note' => '', 'at' => '', 'stale' => false, 'age' => null];

        $ts = strtotime($at);
        $ageSec = $ts !== false ? max(0, time() - $ts) : null;
        return [
            'state' => $state,
            'note' => $note,
            'at' => $at,
            'stale' => $ageSec !== null && $ageSec > 86400,
            'age' => $ageSec === null ? null : self::ago($ageSec),
        ];
    }

    private static function ago(int $sec): string
    {
        if ($sec < 60) return 'just now';
        if ($sec < 3600) return intdiv($sec, 60) . ' min ago';
        if ($sec < 86400) return intdiv($sec, 3600) . ' h ago';
        $d = intdiv($sec, 86400);
        return $d === 1 ? 'yesterday' : $d . ' days ago';
    }

    /** The states a lamp can be in, in the order they read worst to best. */
    public const STATES = ['off' => 'Not set', 'bad' => 'Not working', 'idle' => 'Not tested', 'ok' => 'Working'];
}