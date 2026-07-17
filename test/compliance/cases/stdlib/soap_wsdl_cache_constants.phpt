--TEST--
stdlib soap WSDL_CACHE_* / AUTH / COMPRESSION / SSL_METHOD constants (#20220, #20295, ext/soap/php_soap.h)
--FILE--
<?php
$expect = [
    'WSDL_CACHE_NONE' => 0,
    'WSDL_CACHE_DISK' => 1,
    'WSDL_CACHE_MEMORY' => 2,
    'WSDL_CACHE_BOTH' => 3,
    'SOAP_AUTHENTICATION_BASIC' => 0,
    'SOAP_AUTHENTICATION_DIGEST' => 1,
    'SOAP_COMPRESSION_ACCEPT' => 32,
    'SOAP_COMPRESSION_GZIP' => 0,
    'SOAP_COMPRESSION_DEFLATE' => 16,
    'SOAP_SSL_METHOD_TLS' => 0,
    'SOAP_SSL_METHOD_SSLv2' => 1,
    'SOAP_SSL_METHOD_SSLv3' => 2,
    'SOAP_SSL_METHOD_SSLv23' => 3,
];
$ok = 1;
foreach ($expect as $name => $val) {
    if (!defined($name) || constant($name) !== $val) {
        $ok = 0;
        echo 'bad=', $name, ' got=', defined($name) ? (string) constant($name) : 'MISSING', "\n";
    }
}
echo 'ok=', $ok, "\n";
echo 'none=', WSDL_CACHE_NONE, "\n";
echo 'tls=', SOAP_SSL_METHOD_TLS, "\n";
?>
--EXPECT--
ok=1
none=0
tls=0
