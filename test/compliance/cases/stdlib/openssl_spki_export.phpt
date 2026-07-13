--TEST--
openssl_spki_export()/openssl_spki_export_challenge() SPKAC round-trip (#6423, ext/openssl/openssl.c)
--FILE--
<?php
if (!function_exists('openssl_spki_new')
    || !function_exists('openssl_spki_export')
    || !function_exists('openssl_spki_export_challenge')) {
    echo "missing\n";
    exit(1);
}
$key = openssl_pkey_new(['private_key_bits' => 512, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
if (false === $key) {
    echo "gen-fail\n";
    exit(1);
}
$challenge = 'probe-challenge-export';
$spki = openssl_spki_new($key, $challenge, OPENSSL_ALGO_SHA256);
if (!is_string($spki) || !str_starts_with($spki, 'SPKAC=')) {
    echo "new-fail\n";
    exit(1);
}
$payload = substr($spki, 6);
$pem = openssl_spki_export($payload);
echo is_string($pem) && str_contains($pem, 'BEGIN PUBLIC KEY') ? "export-ok\n" : "export-fail\n";
$got = openssl_spki_export_challenge($payload);
echo $got === $challenge ? "challenge-ok\n" : "challenge-fail\n";
echo @openssl_spki_export('bad!!!') === false ? "invalid-false\n" : "invalid-wrong\n";
?>
--EXPECT--
export-ok
challenge-ok
invalid-false
