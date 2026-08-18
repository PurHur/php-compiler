--TEST--
stdlib SoapClient SoapVar XSD enc_type unwrap (#32190, ext/soap/php_encoding.c)
--FILE--
<?php
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
$c->__soapCall('echo', [new SoapVar(123, XSD_STRING)]);
$s = (string) $c->__getLastRequest();
echo 'xsd_string=', str_contains($s, 'xsi:type="xsd:string"') && str_contains($s, '>123<') ? '1' : '0', "\n";
echo 'no_bag=', (str_contains($s, 'enc_type') || str_contains($s, 'enc_value')) ? '0' : '1', "\n";
$c->__soapCall('echo', [new SoapVar(1, XSD_BOOLEAN)]);
$b = (string) $c->__getLastRequest();
echo 'xsd_bool=', str_contains($b, 'xsi:type="xsd:boolean"') && str_contains($b, '>true<') ? '1' : '0', "\n";
$c->__soapCall('echo', [new SoapVar('hi', XSD_BASE64BINARY)]);
$b64 = (string) $c->__getLastRequest();
echo 'xsd_b64=', str_contains($b64, 'xsi:type="xsd:base64Binary"') && str_contains($b64, base64_encode('hi')) ? '1' : '0', "\n";
$c->__soapCall('echo', [new SoapVar('hi', XSD_HEXBINARY)]);
$hex = (string) $c->__getLastRequest();
echo 'xsd_hex=', str_contains($hex, 'xsi:type="xsd:hexBinary"') && str_contains($hex, strtoupper(bin2hex('hi'))) ? '1' : '0', "\n";
?>
--EXPECT--
xsd_string=1
no_bag=1
xsd_bool=1
xsd_b64=1
xsd_hex=1
