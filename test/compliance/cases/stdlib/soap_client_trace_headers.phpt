--TEST--
stdlib SoapClient::__getLastRequestHeaders/__getLastResponseHeaders with trace (#20183)
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
echo 'has_req=', method_exists($client, '__getLastRequestHeaders') ? 1 : 0, "\n";
echo 'has_res=', method_exists($client, '__getLastResponseHeaders') ? 1 : 0, "\n";
echo 'before=', var_export($client->__getLastRequestHeaders(), true), "\n";
$out = $client->__soapCall('echo', [['input' => 'hello']]);
echo 'out=', $out, "\n";
$req = $client->__getLastRequestHeaders();
$res = $client->__getLastResponseHeaders();
echo 'req_ok=', (is_string($req) && str_contains($req, 'Content-Type') && str_contains($req, 'SOAPAction')) ? 1 : 0, "\n";
echo 'res_ok=', (is_string($res) && str_contains($res, 'Content-Type')) ? 1 : 0, "\n";

$quiet = new SoapClient($wsdl, [
    'location' => $resp,
    'uri' => 'http://example.com/echo',
    'trace' => 0,
]);
$quiet->__soapCall('echo', [['input' => 'x']]);
echo 'quiet=', var_export($quiet->__getLastRequestHeaders(), true), "\n";
?>
--EXPECT--
has_req=1
has_res=1
before=NULL
out=hello
req_ok=1
res_ok=1
quiet=NULL
