--TEST--
stdlib soap WSDL_CACHE_* / AUTH / COMPRESSION constants (#20220, ext/soap/php_soap.h)
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
?>
--EXPECT--
ok=1
none=0
