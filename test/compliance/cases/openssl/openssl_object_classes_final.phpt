--TEST--
OpenSSL object classes ReflectionClass::isFinal() (php-src ext/openssl/openssl.stub.php; #28370)
--FILE--
<?php
foreach (['OpenSSLCertificate', 'OpenSSLAsymmetricKey', 'OpenSSLCertificateSigningRequest'] as $c) {
    echo $c, '=', (new ReflectionClass($c))->isFinal() ? "yes\n" : "no\n";
}
?>
--EXPECT--
OpenSSLCertificate=yes
OpenSSLAsymmetricKey=yes
OpenSSLCertificateSigningRequest=yes
