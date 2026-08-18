<?php
/**
 * Repro #32269 — SoapVar XSD_DATETIME DateTimeInterface php_format_date_obj
 * (php-src php_encoding.c to_xml_datetime_ex IS_OBJECT).
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

$dt = new DateTime('2023-11-14 22:13:20.123456', new DateTimeZone('UTC'));
$c->__soapCall('echo', [new SoapVar($dt, XSD_DATETIME)]);
$s = (string) $c->__getLastRequest();
echo 'utc_obj=', str_contains($s, 'xsi:type="xsd:dateTime">2023-11-14T22:13:20.123456Z<') ? '1' : '0', "\n";
echo 'not_array=', str_contains($s, '>Array<') ? '0' : '1', "\n";

$c->__soapCall('echo', [new SoapVar(1700000000, XSD_DATETIME)]);
$long = (string) $c->__getLastRequest();
echo 'long_unfractioned=', str_contains($long, '>2023-11-14T22:13:20Z<') ? '1' : '0', "\n";
