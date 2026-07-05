--TEST--
openssl_pkey_new/get_private/export lifecycle (#6295, ext/openssl/xp.c)
--FILE--
<?php
if (!function_exists('openssl_pkey_new')) {
    echo "missing\n";
    exit(1);
}
$key = openssl_pkey_new(['private_key_bits' => 512, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
if (false === $key) {
    echo "gen-fail\n";
    exit(1);
}
$pem = '';
if (!openssl_pkey_export($key, $pem)) {
    echo "export-fail\n";
    exit(1);
}
$loaded = openssl_pkey_get_private($pem);
if (false === $loaded) {
    echo "load-fail\n";
    exit(1);
}
$data = 'probe';
$sig = '';
echo openssl_sign($data, $sig, $loaded, OPENSSL_ALGO_SHA256) && $sig !== '' ? "ok\n" : "sign-fail\n";
?>
--EXPECT--
ok
