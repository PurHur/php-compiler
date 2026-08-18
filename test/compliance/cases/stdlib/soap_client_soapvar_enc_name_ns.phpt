--TEST--
stdlib SoapClient SoapVar enc_name/enc_namens (#32191, ext/soap/php_encoding.c)
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
$c->__soapCall('echo', [new SoapVar('hello', XSD_STRING, null, null, 'input')]);
$n = (string) $c->__getLastRequest();
echo 'name_only=', str_contains($n, '<input xsi:type="xsd:string">hello</input>') ? '1' : '0', "\n";
echo 'no_param0=', str_contains($n, 'param0') ? '0' : '1', "\n";
$c->__soapCall('echo', [new SoapVar('hello', XSD_STRING, null, null, 'input', 'http://example.com/echo')]);
$ns = (string) $c->__getLastRequest();
echo 'ns1_input=', str_contains($ns, '<ns1:input xsi:type="xsd:string">hello</ns1:input>') ? '1' : '0', "\n";
?>
--EXPECT--
name_only=1
no_param0=1
ns1_input=1
