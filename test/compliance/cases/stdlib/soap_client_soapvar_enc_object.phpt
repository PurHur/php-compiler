--TEST--
stdlib SoapClient SoapVar SOAP_ENC_OBJECT Struct (#32192, ext/soap/php_encoding.c)
--FILE--
<?php
$resp = __DIR__ . '/test/fixtures/soap/echo.response.xml';
if (!is_file($resp)) {
    $resp = dirname(__DIR__, 3) . '/fixtures/soap/echo.response.xml';
}
$opts = [
    'location' => $resp,
    'uri' => 'http://example.com/echo',
    'trace' => 1,
    'exceptions' => false,
    'style' => SOAP_RPC,
    'use' => SOAP_ENCODED,
];
$c = new SoapClient(null, $opts);
$c->__soapCall('echo', [new SoapVar(['a' => 1, 'b' => 'x'], SOAP_ENC_OBJECT)]);
$s11 = (string) $c->__getLastRequest();
echo 's11_struct=', str_contains($s11, 'xsi:type="SOAP-ENC:Struct"') ? '1' : '0', "\n";
echo 's11_a=', str_contains($s11, '<a xsi:type="xsd:int">1</a>') ? '1' : '0', "\n";
echo 's11_b=', str_contains($s11, '<b xsi:type="xsd:string">x</b>') ? '1' : '0', "\n";
$c12 = new SoapClient(null, $opts + ['soap_version' => SOAP_1_2]);
$c12->__soapCall('echo', [new SoapVar(['a' => 1], SOAP_ENC_OBJECT)]);
$s12 = (string) $c12->__getLastRequest();
echo 's12_struct=', str_contains($s12, 'xsi:type="enc:Struct"') ? '1' : '0', "\n";
?>
--EXPECT--
s11_struct=1
s11_a=1
s11_b=1
s12_struct=1
