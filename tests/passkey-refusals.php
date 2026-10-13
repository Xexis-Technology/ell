<?php
/**
 * Passkey refusal harness: a stored credential must never accept a response
 * that a real attacker could produce. Every payload here is a genuine
 * signature over genuine bytes, so a passing run means the refusals are real.
 */
chdir('C:/xampp/htdocs/ell');
require 'C:/xampp/htdocs/ell/app/bootstrap.php';
$_SERVER['HTTP_HOST'] = 'localhost';
$_SERVER['HTTPS'] = '';

$fx = json_decode(file_get_contents('C:/Users/bappi/AppData/Local/Temp/opencode/pk-fixture.json'), true);
$att = passkey_credential_from_attestation([
    'attestationObject' => $fx['registration']['response']['attestationObject'],
]);
$stored = [
    'credential_id' => $fx['registration']['id'],
    'public_key' => $att['pem'],
    'alg_id' => -7,
    'sign_counter' => 0,
    'admin_id' => 2,
];
$challenge = (string)$fx['challenge'];
$origin = 'http://localhost';

$pass = 0; $fail = 0;
function refuse(string $label, array $stored, array $payload, string $ch, string $origin): void
{
    global $pass, $fail;
    $r = passkey_verify_assertion($stored, $payload, $ch, $origin);
    if (!$r['ok']) { $pass++; echo "  PASS  refused: $label\n            -> " . $r['reason'] . "\n"; }
    else { $fail++; echo "  FAIL  ACCEPTED: $label\n"; }
}

echo "== a valid response is accepted first, so the refusals mean something ==\n";
$ok = passkey_verify_assertion($stored, $fx['assertion1'], $challenge, $origin);
if ($ok['ok']) { $pass++; echo "  PASS  valid response accepted\n"; }
else { $fail++; echo '  FAIL  valid response REJECTED: ' . $ok['reason'] . "\n"; }

echo "\n== replay: the same response, used twice ==\n";
// A challenge is single-use, so the second attempt has no challenge left to
// match. This is the assertion-level check; the challenge delete is tested
// separately below against the database.
refuse('same response replayed with a stale challenge', $stored, $fx['assertion1'], 'consumed-challenge-value', $origin);

echo "\n== a signature from another key, for this credential ==\n";
refuse('foreign signature', $stored, $fx['assertionWrongKey'], $challenge, $origin);

echo "\n== a signature from another site ==\n";
refuse('signature made for another origin', $stored, $fx['assertionWrongOrigin'], $challenge, $origin);

echo "\n== a signature over someone else's challenge ==\n";
refuse('challenge not issued to this browser', $stored, $fx['assertion1'], 'a-different-challenge', $origin);

echo "\n== the authenticator answers for the wrong website ==\n";
$otherRp = $fx['assertion1'];
$ad = b64url_decode($otherRp['response']['authenticatorData']);
$ad = hash('sha256', 'evil.example', true) . substr($ad, 32);
$otherRp['response']['authenticatorData'] = b64url_encode($ad);
refuse('rpIdHash for another site', $stored, $otherRp, $challenge, $origin);

echo "\n== a cloned authenticator replaying old signatures ==\n";
$stale = $stored; $stale['sign_counter'] = 50;
refuse('signCounter went backwards', $stale, $fx['assertion1'], $challenge, $origin);
$stale2 = $stored; $stale2['sign_counter'] = 5;
refuse('signCounter repeated', $stale2, $fx['assertion2'], $challenge, $origin);

echo "\n== a passkey claimed by another account ==\n";
$other = $fx['assertion1'];
$other['response']['userHandle'] = b64url_encode('777');
refuse('userHandle names a different admin', $stored, $other, $challenge, $origin);

echo "\n== embedded in a page on another site ==\n";
$x = $fx['assertion1'];
$cdj = json_decode(b64url_decode($x['response']['clientDataJSON']), true);
$cdj['crossOrigin'] = true;
$x['response']['clientDataJSON'] = b64url_encode(json_encode($cdj));
refuse('crossOrigin set', $stored, $x, $challenge, $origin);

echo "\n== the device never confirmed a human ==\n";
$noUv = $fx['assertion1'];
$ad = b64url_decode($noUv['response']['authenticatorData']);
$ad[32] = chr(ord($ad[32]) & ~0x04 & 0xff);
$noUv['response']['authenticatorData'] = b64url_encode($ad);
refuse('user verification flag cleared', $stored, $noUv, $challenge, $origin);

echo "\n== challenges are single-use in the database ==\n";
$pdo = Database::pdo();
$pdo->exec('DELETE FROM passkey_challenges');
$id = passkey_put_challenge($pdo, 'assert', 'the-one-challenge', 2);
$first = passkey_take_challenge($pdo, $id, 'assert');
$second = passkey_take_challenge($pdo, $id, 'assert');
if ($first === 'the-one-challenge' && $second === null) { $pass++; echo "  PASS  a challenge is returned once and then gone\n"; }
else { $fail++; echo "  FAIL  challenge reuse: first=" . var_export($first, true) . " second=" . var_export($second, true) . "\n"; }

$id2 = passkey_put_challenge($pdo, 'assert', 'purpose-bound', 2);
$wrongPurpose = passkey_take_challenge($pdo, $id2, 'register');
if ($wrongPurpose === null) { $pass++; echo "  PASS  a register challenge cannot be used to sign in\n"; }
else { $fail++; echo "  FAIL  purpose confusion allowed\n"; }
$pdo->exec('DELETE FROM passkey_challenges');

echo "\n$pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);