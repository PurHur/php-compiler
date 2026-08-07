--TEST--
class cannot extend final OpenSSLCertificate (php-src ext/openssl/openssl.stub.php; #28370)
--FILE--
<?php
class BadOpenSSLCertificate extends OpenSSLCertificate {}
echo "EXTENDED_OK\n";
?>
--EXPECT_EXIT--
255
--EXPECTREGEX--
Class BadOpenSSLCertificate cannot extend final class OpenSSLCertificate
