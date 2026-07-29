<?php
// OPENSSL_TLSEXT_SERVER_NAME (#24084) — matches Zend when OpenSSL has TLS SNI.
foreach (['OPENSSL_TLSEXT_SERVER_NAME', 'OPENSSL_RAW_DATA', 'OPENSSL_KEYTYPE_RSA'] as $c) {
    echo $c, "\t", defined($c) ? var_export(constant($c), true) : 'UNDEF', "\n";
}
