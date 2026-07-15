--TEST--
openssl openssl_digest(null) — coerces to empty-string SHA256 (#19039, ext/openssl/openssl.c)
--FILE--
<?php
echo openssl_digest(null, 'sha256'), "\n";
?>
--EXPECT--
e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855
