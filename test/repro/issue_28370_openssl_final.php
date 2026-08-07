<?php

declare(strict_types=1);

/**
 * Repro #28370 — OpenSSLCertificate / OpenSSLAsymmetricKey /
 * OpenSSLCertificateSigningRequest must be final
 * (php-src ext/openssl/openssl.stub.php).
 *
 * Run: PHP_COMPILER_PROFILE=8.4 php bin/vm.php test/repro/issue_28370_openssl_final.php
 */
foreach (['OpenSSLCertificate', 'OpenSSLAsymmetricKey', 'OpenSSLCertificateSigningRequest'] as $c) {
    $r = new ReflectionClass($c);
    echo "$c isFinal=", var_export($r->isFinal(), true), "\n";
}
eval('class Bad_OpenSSLCertificate extends OpenSSLCertificate {}');
echo "EXTENDED_OK\n";
