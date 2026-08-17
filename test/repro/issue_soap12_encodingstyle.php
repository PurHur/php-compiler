<?php
/**
 * Repro: SOAP 1.2 encoded encodingStyle URI (php-src soap.c / php_encoding.c; #31919).
 */
declare(strict_types=1);

if (!class_exists('SoapClient')) {
    echo "soap_unavailable\n";
    exit(0);
}

$root = dirname(__DIR__);
$resp = $root.'/fixtures/soap/echo.response.xml';

$c12 = new SoapClient(null, [
    'location' => $resp,
    'uri' => 'http://example.com/echo',
    'trace' => 1,
    'exceptions' => false,
    'soap_version' => SOAP_1_2,
    'style' => SOAP_RPC,
    'use' => SOAP_ENCODED,
]);
$c12->__soapCall('echo', [['input' => 'hello']]);
$req12 = (string) $c12->__getLastRequest();
echo 'enc12=', str_contains($req12, 'http://www.w3.org/2003/05/soap-encoding') ? '1' : '0', "\n";
echo 'no_enc11=', str_contains($req12, 'http://schemas.xmlsoap.org/soap/encoding/') ? '0' : '1', "\n";
echo 'env_prefix=', str_contains($req12, 'env:encodingStyle=') ? '1' : '0', "\n";
echo 'no_soapenv=', str_contains($req12, 'SOAP-ENV:encodingStyle=') ? '0' : '1', "\n";

$c11 = new SoapClient(null, [
    'location' => $resp,
    'uri' => 'http://example.com/echo',
    'trace' => 1,
    'exceptions' => false,
    'soap_version' => SOAP_1_1,
    'style' => SOAP_RPC,
    'use' => SOAP_ENCODED,
]);
$c11->__soapCall('echo', [['input' => 'hello']]);
$req11 = (string) $c11->__getLastRequest();
echo 'enc11=', str_contains($req11, 'http://schemas.xmlsoap.org/soap/encoding/') ? '1' : '0', "\n";
echo 'no_enc12=', str_contains($req11, 'http://www.w3.org/2003/05/soap-encoding') ? '0' : '1', "\n";
echo 'soapenv=', str_contains($req11, 'SOAP-ENV:encodingStyle=') ? '1' : '0', "\n";
