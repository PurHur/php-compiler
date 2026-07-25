--TEST--
openssl opaque object serialize()/unserialize() reject (issue #23100, ext/openssl/openssl.stub.php)
--FILE--
<?php
$dn = ['countryName' => 'US', 'commonName' => 'example.com'];
$priv = openssl_pkey_new(['private_key_bits' => 512, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
$csr = openssl_csr_new($dn, $priv, ['digest_alg' => 'sha256']);
$x509 = openssl_csr_sign($csr, null, $priv, 365, ['digest_alg' => 'sha256']);

try {
    serialize($priv);
    echo "OpenSSLAsymmetricKey serialize:no-throw\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
try {
    unserialize('O:20:"OpenSSLAsymmetricKey":0:{}');
    echo "OpenSSLAsymmetricKey unserialize:no-throw\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}

try {
    serialize($csr);
    echo "OpenSSLCertificateSigningRequest serialize:no-throw\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
try {
    unserialize('O:32:"OpenSSLCertificateSigningRequest":0:{}');
    echo "OpenSSLCertificateSigningRequest unserialize:no-throw\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}

try {
    serialize($x509);
    echo "OpenSSLCertificate serialize:no-throw\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
try {
    unserialize('O:18:"OpenSSLCertificate":0:{}');
    echo "OpenSSLCertificate unserialize:no-throw\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
--EXPECT--
Exception:Serialization of 'OpenSSLAsymmetricKey' is not allowed
Exception:Unserialization of 'OpenSSLAsymmetricKey' is not allowed
Exception:Serialization of 'OpenSSLCertificateSigningRequest' is not allowed
Exception:Unserialization of 'OpenSSLCertificateSigningRequest' is not allowed
Exception:Serialization of 'OpenSSLCertificate' is not allowed
Exception:Unserialization of 'OpenSSLCertificate' is not allowed
