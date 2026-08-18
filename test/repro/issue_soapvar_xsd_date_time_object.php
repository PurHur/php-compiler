<?php
/**
 * Repro #32270 — SoapVar XSD_DATE / XSD_TIME DateTimeInterface (php-src php_encoding.c).
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
$c->__soapCall('echo', [new SoapVar($dt, XSD_DATE)]);
$date = (string) $c->__getLastRequest();
echo 'utc_date=', str_contains($date, 'xsi:type="xsd:date">2023-11-14Z<') ? '1' : '0', "\n";
echo 'date_not_array=', str_contains($date, '>Array<') ? '0' : '1', "\n";

$c->__soapCall('echo', [new SoapVar($dt, XSD_TIME)]);
$time = (string) $c->__getLastRequest();
echo 'utc_time=', str_contains($time, 'xsi:type="xsd:time">22:13:20.123456Z<') ? '1' : '0', "\n";
echo 'time_not_array=', str_contains($time, '>Array<') ? '0' : '1', "\n";

$c->__soapCall('echo', [new SoapVar(1700000000, XSD_TIME)]);
$long = (string) $c->__getLastRequest();
echo 'long_unfractioned=', str_contains($long, '>22:13:20Z<') ? '1' : '0', "\n";
