--TEST--
stdlib SoapClient::__setCookie/__getCookies HTTP cookie jar (#20184)
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
echo 'has=', (method_exists($client, '__setCookie') && method_exists($client, '__getCookies')) ? 1 : 0, "\n";
$client->__setCookie('a', '1');
$client->__setCookie('b', '2');
$c = $client->__getCookies();
echo 'map=', (is_array($c['a'] ?? null) ? ($c['a'][0] ?? '') : ($c['a'] ?? '')), ',', (is_array($c['b'] ?? null) ? ($c['b'][0] ?? '') : ($c['b'] ?? '')), "\n";
$client->__soapCall('echo', [['input' => 'x']]);
$req = $client->__getLastRequestHeaders();
echo 'trace_cookie=', (is_string($req) && str_contains($req, 'Cookie: a=1; b=2')) ? 1 : 0, "\n";
$client->__setCookie('a');
$c2 = $client->__getCookies();
echo 'rm=', isset($c2['a']) ? 1 : 0, "\n";
echo 'keep=', (is_array($c2['b'] ?? null) ? (($c2['b'][0] ?? '') === '2') : (($c2['b'] ?? '') === '2')) ? 1 : 0, "\n";
?>
--EXPECT--
has=1
map=1,2
trace_cookie=1
rm=0
keep=1
