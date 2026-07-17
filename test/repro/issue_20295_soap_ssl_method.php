<?php

/**
 * Repro #20295 — SOAP_SSL_METHOD_* constants.
 */
foreach (['SOAP_SSL_METHOD_TLS', 'SOAP_SSL_METHOD_SSLv2', 'SOAP_SSL_METHOD_SSLv3', 'SOAP_SSL_METHOD_SSLv23'] as $n) {
    echo $n, '=', defined($n) ? (string) constant($n) : 'MISSING', "\n";
}
