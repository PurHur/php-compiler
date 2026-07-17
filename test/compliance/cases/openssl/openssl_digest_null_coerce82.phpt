--TEST--
openssl openssl_digest(null) still coerces on 8.2 profile (#20207, ext/openssl/openssl.c)
--ENV--
PHP_COMPILER_PROFILE=8.2
--FILE--
<?php
echo openssl_digest(null, 'sha256'), "\n";
?>
--EXPECT--
e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855
