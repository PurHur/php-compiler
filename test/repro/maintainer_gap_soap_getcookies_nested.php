<?php
// Repro #31569 — SoapClient::__getCookies nested jar (php-src soap.c __setCookie).
// Requires host ext/soap so SoapExtensionPolicy advertises.

$wsdl = __DIR__.'/../fixtures/soap/echo.wsdl';
$resp = __DIR__.'/../fixtures/soap/echo.response.xml';
$client = new SoapClient($wsdl, [
    'location' => $resp,
    'uri' => 'http://example.com/echo',
    'trace' => 1,
    'cache_wsdl' => WSDL_CACHE_NONE,
]);
$client->__setCookie('a', '1');
$client->__setCookie('b', '2');
$c = $client->__getCookies();
echo 'shape=', (is_array($c['a'] ?? null) && ($c['a'][0] ?? null) === '1'
    && is_array($c['b'] ?? null) && ($c['b'][0] ?? null) === '2') ? 'nested' : 'flat', "\n";
echo var_export($c, true), "\n";
