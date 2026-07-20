--TEST--
openssl openssl_encrypt(null) soft-null on 8.4 forward JIT (#21445, ext/openssl/openssl.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--JIT--
--FILE--
<?php
$key = str_repeat('k', 16);
$empty = openssl_encrypt('', 'AES-128-ECB', $key);
$null = openssl_encrypt(null, 'AES-128-ECB', $key);
echo 'same='.(($empty === $null) ? '1' : '0')."\n";
echo 'len='.strlen((string) $empty)."\n";
?>
--EXPECT--
same=1
len=24
