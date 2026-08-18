<?php
/**
 * Repro #32237 — SoapVar XSD_DATETIME unix timestamp ISO-8601 (php-src php_encoding.c to_xml_datetime).
 */
declare(strict_types=1);

if (!class_exists('SoapClient')) {
    echo "soap_unavailable\n";
    exit(0);
}

date_default_timezone_set('UTC');

$resp = dirname(__DIR__).'/fixtures/soap/echo.response.xml';
$c = new SoapClient(null, [
    'location' => $resp,
    'uri' => 'http://example.com/echo',
    'trace' => 1,
    'exceptions' => false,
    'style' => SOAP_RPC,
    'use' => SOAP_ENCODED,
]);

$c->__soapCall('echo', [new SoapVar(1700000000, XSD_DATETIME)]);
$s = (string) $c->__getLastRequest();
echo 'utc_iso=', str_contains($s, 'xsi:type="xsd:dateTime">2023-11-14T22:13:20Z<') ? '1' : '0', "\n";
echo 'not_epoch=', str_contains($s, '>1700000000<') ? '0' : '1', "\n";

$c->__soapCall('echo', [new SoapVar('already-iso', XSD_DATETIME)]);
$pass = (string) $c->__getLastRequest();
echo 'str_passthrough=', str_contains($pass, 'xsi:type="xsd:dateTime">already-iso<') ? '1' : '0', "\n";
