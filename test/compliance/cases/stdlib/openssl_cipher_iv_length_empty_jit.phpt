--TEST--
stdlib openssl_cipher_iv_length() JIT — empty cipher ValueError (#18154)
--FILE--
<?php
try {
    openssl_cipher_iv_length('');
} catch (ValueError $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
openssl_cipher_iv_length(): Argument #1 ($cipher_algo) must not be empty
