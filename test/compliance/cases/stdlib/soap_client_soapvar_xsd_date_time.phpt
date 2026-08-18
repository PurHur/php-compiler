--TEST--
stdlib SoapClient SoapVar XSD_DATE/XSD_TIME unix timestamps (#32239, ext/soap/php_encoding.c)
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
$c->__soapCall('echo', [new SoapVar(1700000000, XSD_DATE)]);
$d = (string) $c->__getLastRequest();
echo 'utc_date=', str_contains($d, 'xsi:type="xsd:date">2023-11-14Z<') ? '1' : '0', "\n";
echo 'date_not_epoch=', str_contains($d, '>1700000000<') ? '0' : '1', "\n";
$c->__soapCall('echo', [new SoapVar(1700000000, XSD_TIME)]);
$t = (string) $c->__getLastRequest();
echo 'utc_time=', str_contains($t, 'xsi:type="xsd:time">22:13:20Z<') ? '1' : '0', "\n";
echo 'time_not_epoch=', str_contains($t, '>1700000000<') ? '0' : '1', "\n";
?>
--EXPECT--
utc_date=1
date_not_epoch=1
utc_time=1
time_not_epoch=1
