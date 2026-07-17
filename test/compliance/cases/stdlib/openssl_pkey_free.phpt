--TEST--
openssl_pkey_free() deprecated noop (#20271, ext/openssl/openssl.c)
--FILE--
<?php
echo (int) function_exists('openssl_pkey_free'), "\n";
$key = openssl_pkey_new(['private_key_bits' => 512, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
if (false === $key) {
    echo "gen-fail\n";
    exit(1);
}
$prev = error_reporting(E_ALL);
try {
    $r = @openssl_pkey_free($key);
    var_export($r);
    echo "\n";
} finally {
    error_reporting($prev);
}
try {
    openssl_pkey_free(null);
    echo "null-ok\n";
} catch (TypeError $e) {
    echo "null-type\n";
}
try {
    openssl_pkey_free();
    echo "argc-ok\n";
} catch (ArgumentCountError $e) {
    echo "argc\n";
}
?>
--EXPECT--
1
NULL
null-type
argc
