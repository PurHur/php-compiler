<?php
/**
 * Repro #32240 — SoapVar XSD_GYEAR/gMonth/gDay unix timestamps (php-src php_encoding.c to_xml_gyear*).
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

$ts = 1700000000;
$c->__soapCall('echo', [new SoapVar($ts, XSD_GYEAR)]);
$s = (string) $c->__getLastRequest();
echo 'gyear=', str_contains($s, 'xsi:type="xsd:gYear">2023Z<') && !str_contains($s, '>1700000000<') ? '1' : '0', "\n";
$c->__soapCall('echo', [new SoapVar($ts, XSD_GYEARMONTH)]);
$s = (string) $c->__getLastRequest();
echo 'gyearmonth=', str_contains($s, 'xsi:type="xsd:gYearMonth">2023-11Z<') && !str_contains($s, '>1700000000<') ? '1' : '0', "\n";
$c->__soapCall('echo', [new SoapVar($ts, XSD_GMONTHDAY)]);
$s = (string) $c->__getLastRequest();
echo 'gmonthday=', str_contains($s, 'xsi:type="xsd:gMonthDay">--11-14Z<') && !str_contains($s, '>1700000000<') ? '1' : '0', "\n";
$c->__soapCall('echo', [new SoapVar($ts, XSD_GDAY)]);
$s = (string) $c->__getLastRequest();
echo 'gday=', str_contains($s, 'xsi:type="xsd:gDay">---14Z<') && !str_contains($s, '>1700000000<') ? '1' : '0', "\n";
$c->__soapCall('echo', [new SoapVar($ts, XSD_GMONTH)]);
$s = (string) $c->__getLastRequest();
echo 'gmonth=', str_contains($s, 'xsi:type="xsd:gMonth">--11--Z<') && !str_contains($s, '>1700000000<') ? '1' : '0', "\n";
