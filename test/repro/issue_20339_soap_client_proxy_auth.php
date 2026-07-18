<?php
$wsdl = __DIR__ . '/../fixtures/soap/echo.wsdl';
$resp = __DIR__ . '/../fixtures/soap/echo.response.xml';
$c = new SoapClient($wsdl, [
  'location' => $resp,
  'uri' => 'http://example.com/echo',
  'trace' => 1,
  'proxy_host' => '127.0.0.1',
  'proxy_port' => 8080,
  'proxy_login' => 'proxuser',
  'proxy_password' => 'proxpass',
]);
$c->__soapCall('echo', [['input' => 'hi']]);
$h = $c->__getLastRequestHeaders();
echo (is_string($h) && str_contains($h, 'Proxy-Authorization: Basic ')) ? 'proxy_auth=1' : 'proxy_auth=0';
echo "\n";
echo (is_string($h) && str_contains($h, base64_encode('proxuser:proxpass'))) ? 'b64=1' : 'b64=0';
echo "\n";

$noProxy = new SoapClient($wsdl, [
  'location' => $resp,
  'uri' => 'http://example.com/echo',
  'trace' => 1,
]);
$noProxy->__soapCall('echo', [['input' => 'hi']]);
$h2 = $noProxy->__getLastRequestHeaders();
echo (is_string($h2) && !str_contains($h2, 'Proxy-Authorization:')) ? 'none=1' : 'none=0';
echo "\n";
