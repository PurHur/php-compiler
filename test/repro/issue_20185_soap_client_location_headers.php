<?php
$wsdl = __DIR__ . '/../fixtures/soap/echo.wsdl';
$resp = __DIR__ . '/../fixtures/soap/echo.response.xml';

$client = new SoapClient($wsdl, [
    'location' => $resp,
    'uri' => 'http://example.com/echo',
    'trace' => 1,
]);

echo 'has_loc=', method_exists($client, '__setLocation') ? 1 : 0, "\n";
echo 'has_hdr=', method_exists($client, '__setSoapHeaders') ? 1 : 0, "\n";

$prev = $client->__setLocation('/tmp/other');
echo 'prev_is_resp=', ($prev === $resp) ? 1 : 0, "\n";
$back = $client->__setLocation($resp);
echo 'back=', $back, "\n";

$h = new SoapHeader('http://example.com/auth', 'Token', 'secret', true);
$ok = $client->__setSoapHeaders($h);
echo 'set_ok=', $ok ? 1 : 0, "\n";
$client->__soapCall('echo', [['input' => 'hello']]);
$req = $client->__getLastRequest();
echo 'has_header=', (is_string($req) && str_contains($req, ':Header') && str_contains($req, 'Token') && str_contains($req, 'secret')) ? 1 : 0, "\n";
echo 'must=', (is_string($req) && str_contains($req, 'mustUnderstand="1"')) ? 1 : 0, "\n";

$client->__setSoapHeaders(null);
$client->__soapCall('echo', [['input' => 'hello']]);
$req2 = $client->__getLastRequest();
echo 'cleared=', (is_string($req2) && !str_contains($req2, ':Header')) ? 1 : 0, "\n";
