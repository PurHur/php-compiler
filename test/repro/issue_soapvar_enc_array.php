<?php
/**
 * Repro #32284 — SoapVar SOAP_ENC_ARRAY to_xml_array (php-src php_encoding.c).
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

$c->__soapCall('echo', [new SoapVar(['a' => 1, 'b' => 2], SOAP_ENC_ARRAY)]);
$assoc = (string) $c->__getLastRequest();
echo 'assoc_array_type=', str_contains($assoc, 'xsi:type="SOAP-ENC:Array"') ? '1' : '0', "\n";
echo 'assoc_items=', (str_contains($assoc, '<item xsi:type="xsd:int">1</item>') && str_contains($assoc, '<item xsi:type="xsd:int">2</item>')) ? '1' : '0', "\n";
echo 'assoc_not_keys=', (str_contains($assoc, '<a xsi:type="xsd:int">') || str_contains($assoc, '<b xsi:type="xsd:int">')) ? '0' : '1', "\n";

$c->__soapCall('echo', [new SoapVar(['x', 'y'], SOAP_ENC_ARRAY)]);
$list = (string) $c->__getLastRequest();
echo 'list=', str_contains($list, 'xsi:type="SOAP-ENC:Array"') && str_contains($list, '>x</item>') ? '1' : '0', "\n";
