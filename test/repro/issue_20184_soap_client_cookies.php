<?php
$wsdl = __DIR__ . '/../fixtures/soap/echo.wsdl';
$resp = __DIR__ . '/../fixtures/soap/echo.response.xml';

$client = new SoapClient($wsdl, [
    'location' => $resp,
    'uri' => 'http://example.com/echo',
    'trace' => 1,
]);

echo 'has_set=', method_exists($client, '__setCookie') ? 1 : 0, "\n";
echo 'has_get=', method_exists($client, '__getCookies') ? 1 : 0, "\n";

$client->__setCookie('sid', 'abc');
$client->__setCookie('tok', 'xyz');
$c = $client->__getCookies();
echo 'count=', count($c), "\n";
echo 'sid=', is_array($c['sid'] ?? null) ? ($c['sid'][0] ?? 'missing') : ($c['sid'] ?? 'missing'), "\n";
echo 'tok=', is_array($c['tok'] ?? null) ? ($c['tok'][0] ?? 'missing') : ($c['tok'] ?? 'missing'), "\n";

$client->__soapCall('echo', [['input' => 'hello']]);
$req = $client->__getLastRequestHeaders();
echo 'cookie_in_trace=', (is_string($req) && str_contains($req, 'Cookie: sid=abc; tok=xyz')) ? 1 : 0, "\n";

$client->__setCookie('sid'); // remove
$c2 = $client->__getCookies();
echo 'after_rm=', isset($c2['sid']) ? 1 : 0, "\n";
echo 'tok_kept=', is_array($c2['tok'] ?? null) ? ((($c2['tok'][0] ?? '') === 'xyz') ? 1 : 0) : ((($c2['tok'] ?? '') === 'xyz') ? 1 : 0), "\n";
