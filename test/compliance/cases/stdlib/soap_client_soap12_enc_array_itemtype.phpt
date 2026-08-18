--TEST--
stdlib SoapClient SOAP 1.2 encoded arrays enc:itemType/arraySize (#32220, ext/soap/php_encoding.c)
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
    'soap_version' => SOAP_1_2,
];
$c = new SoapClient(null, $opts);
$c->__soapCall('echo', [[1, 2, 3]]);
$s = (string) $c->__getLastRequest();
echo 'itemType=', str_contains($s, 'enc:itemType="xsd:int"') ? '1' : '0', "\n";
echo 'arraySize=', str_contains($s, 'enc:arraySize="3"') ? '1' : '0', "\n";
echo 'enc_Array=', str_contains($s, 'xsi:type="enc:Array"') ? '1' : '0', "\n";
echo 'no_soapenc_arrayType=', str_contains($s, 'SOAP-ENC:arrayType') ? '0' : '1', "\n";
$c->__soapCall('echo', [new SoapVar([1, 2], SOAP_ENC_ARRAY)]);
$sv = (string) $c->__getLastRequest();
echo 'sv_itemType=', str_contains($sv, 'enc:itemType="xsd:int"') ? '1' : '0', "\n";
echo 'sv_arraySize=', str_contains($sv, 'enc:arraySize="2"') ? '1' : '0', "\n";
echo 'sv_enc_Array=', str_contains($sv, 'xsi:type="enc:Array"') ? '1' : '0', "\n";
?>
--EXPECT--
itemType=1
arraySize=1
enc_Array=1
no_soapenc_arrayType=1
sv_itemType=1
sv_arraySize=1
sv_enc_Array=1
