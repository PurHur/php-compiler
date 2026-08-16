--TEST--
stdlib SoapClient v1 local WSDL fixture __soapCall (#20037, ext/soap/soap.c)
--FILE--
<?php
echo 'class=', class_exists('SoapClient') ? 1 : 0, "\n";
echo 'ext=', extension_loaded('soap') ? 1 : 0, "\n";
echo 'SOAP_1_1=', defined('SOAP_1_1') ? (string) SOAP_1_1 : 'missing', "\n";

$wsdl = __DIR__ . '/test/fixtures/soap/echo.wsdl';
$resp = __DIR__ . '/test/fixtures/soap/echo.response.xml';
if (!is_file($wsdl)) {
    $wsdl = dirname(__DIR__, 3) . '/fixtures/soap/echo.wsdl';
    $resp = dirname(__DIR__, 3) . '/fixtures/soap/echo.response.xml';
}
$client = new SoapClient($wsdl, [
    'location' => $resp,
    'uri' => 'http://example.com/echo',
    'trace' => 1,
]);
$out = $client->__soapCall('echo', [['input' => 'hello']]);
echo 'out=', $out, "\n";
$fns = $client->__getFunctions();
echo 'has_echo=', (is_array($fns) && isset($fns[0]) && $fns[0] === 'echoResponse echo(echo $parameters)') ? 1 : 0, "\n";
echo 'req_ok=', (is_string($client->__getLastRequest()) && str_contains($client->__getLastRequest(), 'echo')) ? 1 : 0, "\n";
?>
--EXPECT--
class=1
ext=1
SOAP_1_1=1
out=hello
has_echo=1
req_ok=1
