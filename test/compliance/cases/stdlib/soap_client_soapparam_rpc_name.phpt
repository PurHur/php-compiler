--TEST--
stdlib SoapClient SoapParam param_name RPC element (#32193, ext/soap/soap.c)
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
$c->__soapCall('echo', [new SoapParam('hello', 'input')]);
$req = (string) $c->__getLastRequest();
echo 'input=', str_contains($req, '<input xsi:type="xsd:string">hello</input>') ? '1' : '0', "\n";
echo 'no_param0=', str_contains($req, 'param0') ? '0' : '1', "\n";
echo 'no_bag=', (str_contains($req, 'param_name') || str_contains($req, 'param_data')) ? '0' : '1', "\n";
?>
--EXPECT--
input=1
no_param0=1
no_bag=1
