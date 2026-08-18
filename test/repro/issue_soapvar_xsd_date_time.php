<?php
/**
 * Repro #32239 — SoapVar XSD_DATE / XSD_TIME unix timestamps (php-src php_encoding.c to_xml_date/time).
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

$c->__soapCall('echo', [new SoapVar(1700000000, XSD_DATE)]);
$d = (string) $c->__getLastRequest();
echo 'utc_date=', str_contains($d, 'xsi:type="xsd:date">2023-11-14Z<') ? '1' : '0', "\n";
echo 'date_not_epoch=', str_contains($d, '>1700000000<') ? '0' : '1', "\n";

$c->__soapCall('echo', [new SoapVar(1700000000, XSD_TIME)]);
$t = (string) $c->__getLastRequest();
echo 'utc_time=', str_contains($t, 'xsi:type="xsd:time">22:13:20Z<') ? '1' : '0', "\n";
echo 'time_not_epoch=', str_contains($t, '>1700000000<') ? '0' : '1', "\n";
