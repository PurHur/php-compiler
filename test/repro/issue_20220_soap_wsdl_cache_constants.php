<?php
/** Repro #20220 — WSDL_CACHE_* / SOAP_AUTHENTICATION_* / SOAP_COMPRESSION_* (php-src php_soap.h). */
$expect = [
    'WSDL_CACHE_NONE' => 0,
    'WSDL_CACHE_DISK' => 1,
    'WSDL_CACHE_MEMORY' => 2,
    'WSDL_CACHE_BOTH' => 3,
    'SOAP_AUTHENTICATION_BASIC' => 0,
    'SOAP_AUTHENTICATION_DIGEST' => 1,
    'SOAP_COMPRESSION_ACCEPT' => 0x20,
    'SOAP_COMPRESSION_GZIP' => 0x00,
    'SOAP_COMPRESSION_DEFLATE' => 0x10,
];
foreach ($expect as $name => $val) {
    echo $name, '=', defined($name) ? (string) constant($name) : 'MISSING',
        ' want=', $val, ' ok=', (defined($name) && constant($name) === $val) ? 1 : 0, "\n";
}
