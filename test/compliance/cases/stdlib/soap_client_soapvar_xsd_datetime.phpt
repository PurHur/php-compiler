--TEST--
stdlib SoapClient SoapVar XSD_DATETIME unix timestamp ISO-8601 (#32237, ext/soap/php_encoding.c)
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
$c->__soapCall('echo', [new SoapVar(1700000000, XSD_DATETIME)]);
$s = (string) $c->__getLastRequest();
echo 'utc_iso=', str_contains($s, 'xsi:type="xsd:dateTime">2023-11-14T22:13:20Z<') ? '1' : '0', "\n";
echo 'not_epoch=', str_contains($s, '>1700000000<') ? '0' : '1', "\n";
$c->__soapCall('echo', [new SoapVar('already-iso', XSD_DATETIME)]);
$pass = (string) $c->__getLastRequest();
echo 'str_passthrough=', str_contains($pass, 'xsi:type="xsd:dateTime">already-iso<') ? '1' : '0', "\n";
?>
--EXPECT--
utc_iso=1
not_epoch=1
str_passthrough=1
