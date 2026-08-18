<?php
/**
 * Repro #32193 — SoapParam param_name is the RPC element (php-src soap.c serialize_parameter).
 */
declare(strict_types=1);

if (!class_exists('SoapClient')) {
    echo "soap_unavailable\n";
    exit(0);
}

$resp = dirname(__DIR__).'/fixtures/soap/echo.response.xml';
$c = new SoapClient(null, [
    'location' => $resp,
    'uri' => 'http://example.com/echo',
    'trace' => 1,
    'exceptions' => false,
    'style' => SOAP_RPC,
    'use' => SOAP_ENCODED,
]);
$c->__soapCall('echo', [new SoapParam('hello', 'input')]);
$req = (string) $c->__getLastRequest();
echo 'input=', str_contains($req, '<input xsi:type="xsd:string">hello</input>') ? '1' : '0', "\n";
echo 'no_param0=', str_contains($req, 'param0') ? '0' : '1', "\n";
echo 'no_bag=', (str_contains($req, 'param_name') || str_contains($req, 'param_data')) ? '0' : '1', "\n";
