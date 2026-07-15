--TEST--
openssl openssl_digest(null) — TypeError on default profile JIT (#19002, ext/openssl/openssl.c)
--FILE--
<?php
try {
    openssl_digest(null, 'sha256');
    echo "uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
?>
--EXPECT--
openssl_digest(): Argument #1 ($data) must be of type string, null given
