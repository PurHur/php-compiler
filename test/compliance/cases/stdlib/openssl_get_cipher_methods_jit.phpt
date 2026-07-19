--TEST--
stdlib openssl_get_cipher_methods() — lists aes-256-cbc JIT (#21103, re-#6228)
--JIT--
--FILE--
<?php
echo function_exists('openssl_get_cipher_methods') ? "exists\n" : "missing\n";
$ciphers = openssl_get_cipher_methods();
echo in_array('aes-256-cbc', $ciphers, true) ? "has_aes\n" : "missing_aes\n";
--EXPECT--
exists
has_aes
