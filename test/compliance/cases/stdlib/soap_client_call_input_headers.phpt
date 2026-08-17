--TEST--
stdlib SoapClient::__soapCall $inputHeaders per-call SoapHeader (#31874)
--FILE--
<?php
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
$h = new SoapHeader('http://example.com/auth', 'Token', 'secret', true);
$client->__soapCall('echo', [['input' => 'hello']], null, $h);
$req = (string) $client->__getLastRequest();
echo 'hdr=', (str_contains($req, 'Token') && str_contains($req, 'mustUnderstand="1"')) ? '1' : '0', "\n";
$client->__soapCall('echo', [['input' => 'hello']]);
echo 'clr=', str_contains((string) $client->__getLastRequest(), 'Token') ? '1' : '0', "\n";
?>
--EXPECT--
hdr=1
clr=0
