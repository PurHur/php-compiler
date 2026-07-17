--TEST--
openssl openssl_digest(null) TypeError on 8.4 forward JIT (#20207, ext/openssl/openssl.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--JIT--
--FILE--
<?php
try {
    echo openssl_digest(null, 'sha256'), "\n";
    echo "uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
echo openssl_digest('', 'sha256'), "\n";
?>
--EXPECT--
openssl_digest(): Argument #1 ($data) must be of type string, null given
e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855
