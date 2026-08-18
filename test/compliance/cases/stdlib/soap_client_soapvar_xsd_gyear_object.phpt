--TEST--
stdlib SoapClient SoapVar XSD_GYEAR family DateTimeInterface php_format_date_obj (#32271, ext/soap/php_encoding.c)
--FILE--
<?php
date_default_timezone_set('UTC');
$resp = __DIR__ . '/test/fixtures/soap/echo.response.xml';
if (!is_file($resp)) {
    $resp = dirname(__DIR__, 3) . '/fixtures/soap/echo.response.xml';
}
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
?>
--EXPECT--
xsd:gYear=1
xsd:gYearMonth=1
xsd:gMonthDay=1
xsd:gDay=1
xsd:gMonth=1
