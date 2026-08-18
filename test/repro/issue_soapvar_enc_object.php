<?php
/**
 * Repro #32192 — SoapVar SOAP_ENC_OBJECT is SOAP-ENC:Struct (php-src php_encoding.c to_xml_object).
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
$c->__soapCall('echo', [new SoapVar(['a' => 1, 'b' => 'x'], SOAP_ENC_OBJECT)]);
$s11 = (string) $c->__getLastRequest();
echo 's11_struct=', str_contains($s11, 'xsi:type="SOAP-ENC:Struct"') ? '1' : '0', "\n";
echo 's11_a=', str_contains($s11, '<a xsi:type="xsd:int">1</a>') ? '1' : '0', "\n";
echo 's11_b=', str_contains($s11, '<b xsi:type="xsd:string">x</b>') ? '1' : '0', "\n";
echo 's11_no_bag=', (str_contains($s11, 'enc_type') || str_contains($s11, 'enc_value')) ? '0' : '1', "\n";

$c12 = new SoapClient(null, $opts + ['soap_version' => SOAP_1_2]);
$c12->__soapCall('echo', [new SoapVar(['a' => 1], SOAP_ENC_OBJECT)]);
$s12 = (string) $c12->__getLastRequest();
echo 's12_struct=', str_contains($s12, 'xsi:type="enc:Struct"') ? '1' : '0', "\n";
echo 's12_no_soapenc=', str_contains($s12, 'SOAP-ENC:Struct') ? '0' : '1', "\n";
