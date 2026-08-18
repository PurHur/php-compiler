<?php
/**
 * Repro — SOAP 1.1 encoded list arrays always xsi:type SOAP-ENC:Array (php-src to_xml_array).
 */
declare(strict_types=1);

if (!class_exists('SoapClient')) {
    echo "soap_unavailable\n";
    exit(0);
}

$resp = dirname(__DIR__).'/fixtures/soap/echo.response.xml';
$opts = [
    'location' => $resp,
    'uri' => 'http://example.com/echo',
    'trace' => 1,
    'exceptions' => false,
    'style' => SOAP_RPC,
    'use' => SOAP_ENCODED,
];

$c = new SoapClient(null, $opts);
$c->__soapCall('echo', [[1, 2]]);
$plain = (string) $c->__getLastRequest();
echo 'plain_arrayType=', str_contains($plain, 'SOAP-ENC:arrayType="xsd:int[2]"') ? '1' : '0', "\n";
echo 'plain_xsi_array=', str_contains($plain, 'xsi:type="SOAP-ENC:Array"') ? '1' : '0', "\n";

$feat = new SoapClient(null, $opts + ['features' => SOAP_USE_XSI_ARRAY_TYPE]);
$feat->__soapCall('echo', [[1, 2]]);
$f = (string) $feat->__getLastRequest();
echo 'feat_xsi_array=', str_contains($f, 'xsi:type="SOAP-ENC:Array"') ? '1' : '0', "\n";

$c->__soapCall('echo', [new SoapVar([1, 2], SOAP_ENC_ARRAY)]);
$sv = (string) $c->__getLastRequest();
echo 'sv_xsi_array=', str_contains($sv, 'xsi:type="SOAP-ENC:Array"') ? '1' : '0', "\n";
