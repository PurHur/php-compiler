--TEST--
stdlib SoapClient::__setLocation/__setSoapHeaders (#20185)
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
echo 'has=', (method_exists($client, '__setLocation') && method_exists($client, '__setSoapHeaders')) ? 1 : 0, "\n";
$prev = $client->__setLocation($resp);
echo 'prev_ok=', ($prev === $resp) ? 1 : 0, "\n";
$h = new SoapHeader('http://example.com/auth', 'Token', 'secret', true);
echo 'set=', $client->__setSoapHeaders($h) ? 1 : 0, "\n";
$client->__soapCall('echo', [['input' => 'x']]);
$req = $client->__getLastRequest();
echo 'hdr=', (is_string($req) && str_contains($req, 'Token') && str_contains($req, 'secret') && str_contains($req, 'mustUnderstand')) ? 1 : 0, "\n";
$client->__setSoapHeaders(null);
$client->__soapCall('echo', [['input' => 'y']]);
$req2 = $client->__getLastRequest();
echo 'clr=', (is_string($req2) && !str_contains($req2, ':Header')) ? 1 : 0, "\n";
?>
--EXPECT--
has=1
prev_ok=1
set=1
hdr=1
clr=1
