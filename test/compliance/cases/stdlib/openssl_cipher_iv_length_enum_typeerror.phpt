--TEST--
stdlib openssl_cipher_iv_length() — enum case operand TypeError (#7331, php-src-strict)
--FILE--
<?php
declare(strict_types=1);
enum E: string { case A = 'aes-256-cbc'; }
try {
    openssl_cipher_iv_length(E::A);
    echo "uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
openssl_cipher_iv_length(): Argument #1 ($cipher_algo) must be of type string, E given
