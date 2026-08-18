<?php
/**
 * Repro #32271 — SoapVar XSD_GYEAR family DateTimeInterface (php-src php_encoding.c).
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
$cases = [
    [XSD_GYEAR, 'xsd:gYear', '2023Z'],
    [XSD_GYEARMONTH, 'xsd:gYearMonth', '2023-11Z'],
    [XSD_GMONTHDAY, 'xsd:gMonthDay', '--11-14Z'],
    [XSD_GDAY, 'xsd:gDay', '---14Z'],
    [XSD_GMONTH, 'xsd:gMonth', '--11--Z'],
];
foreach ($cases as [$type, $xsi, $expect]) {
    $c->__soapCall('echo', [new SoapVar($dt, $type)]);
    $s = (string) $c->__getLastRequest();
    $ok = str_contains($s, 'xsi:type="'.$xsi.'">'.$expect.'<') && !str_contains($s, '>Array<');
    echo $xsi, '=', $ok ? '1' : '0', "\n";
}
