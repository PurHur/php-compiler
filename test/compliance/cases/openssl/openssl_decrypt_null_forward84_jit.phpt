--TEST--
openssl openssl_decrypt(null) soft-null on 8.4 forward JIT (#21445, ext/openssl/openssl.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--JIT--
--FILE--
<?php
$key = str_repeat('k', 16);
$r = openssl_decrypt(null, 'AES-128-ECB', $key);
echo 'result='.var_export($r, true)."\n";
?>
--EXPECT--
result=false
