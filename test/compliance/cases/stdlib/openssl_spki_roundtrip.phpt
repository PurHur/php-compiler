--TEST--
openssl_spki_new()/openssl_spki_verify() SPKAC round-trip (#8690, ext/openssl/openssl.c)
--FILE--
<?php
if (!function_exists('openssl_spki_new') || !function_exists('openssl_spki_verify')) {
    echo "missing\n";
    exit(1);
}
$key = openssl_pkey_new(['private_key_bits' => 512, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
if (false === $key) {
    echo "gen-fail\n";
    exit(1);
}
$spki = openssl_spki_new($key, 'probe-challenge', OPENSSL_ALGO_SHA256);
if (!is_string($spki) || !str_starts_with($spki, 'SPKAC=')) {
    echo "new-fail\n";
    exit(1);
}
$payload = substr($spki, 6);
echo openssl_spki_verify($payload) ? "verify-ok\n" : "verify-fail\n";
echo @openssl_spki_verify('bad!!!') === false ? "invalid-false\n" : "invalid-wrong\n";
?>
--EXPECT--
verify-ok
invalid-false
