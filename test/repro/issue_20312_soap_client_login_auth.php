<?php
$wsdl = __DIR__ . '/../fixtures/soap/echo.wsdl';
$resp = __DIR__ . '/../fixtures/soap/echo.response.xml';
$c = new SoapClient($wsdl, [
    'location' => $resp,
    'uri' => 'http://example.com/echo',
    'trace' => 1,
    'login' => 'alice',
    'password' => 's3cret',
]);
$c->__soapCall('echo', [['input' => 'hi']]);
$h = $c->__getLastRequestHeaders();
echo (is_string($h) && str_contains($h, 'Authorization: Basic ')) ? 'auth=1' : 'auth=0';
echo "\n";
echo (is_string($h) && str_contains($h, base64_encode('alice:s3cret'))) ? 'b64=1' : 'b64=0';
echo "\n";

$noAuth = new SoapClient($wsdl, [
    'location' => $resp,
    'uri' => 'http://example.com/echo',
    'trace' => 1,
]);
$noAuth->__soapCall('echo', [['input' => 'hi']]);
$h2 = $noAuth->__getLastRequestHeaders();
echo (is_string($h2) && !str_contains($h2, 'Authorization:')) ? 'none=1' : 'none=0';
echo "\n";
