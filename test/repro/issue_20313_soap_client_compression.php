<?php
$wsdl = __DIR__ . '/../fixtures/soap/echo.wsdl';
$resp = __DIR__ . '/../fixtures/soap/echo.response.xml';

$c = new SoapClient($wsdl, [
    'location' => $resp,
    'uri' => 'http://example.com/echo',
    'trace' => 1,
    'compression' => SOAP_COMPRESSION_ACCEPT | SOAP_COMPRESSION_GZIP | 9,
]);
$out = $c->__soapCall('echo', [['input' => 'hi']]);
$h = $c->__getLastRequestHeaders();
echo 'out=', $out, "\n";
echo (is_string($h) && str_contains($h, 'Accept-Encoding: gzip, deflate')) ? 'accept=1' : 'accept=0';
echo "\n";
echo (is_string($h) && str_contains($h, 'Content-Encoding: gzip')) ? 'ce=1' : 'ce=0';
echo "\n";

$deflate = new SoapClient($wsdl, [
    'location' => $resp,
    'uri' => 'http://example.com/echo',
    'trace' => 1,
    'compression' => SOAP_COMPRESSION_ACCEPT | SOAP_COMPRESSION_DEFLATE | 6,
]);
$deflate->__soapCall('echo', [['input' => 'hi']]);
$h2 = $deflate->__getLastRequestHeaders();
echo (is_string($h2) && str_contains($h2, 'Content-Encoding: deflate')) ? 'deflate=1' : 'deflate=0';
echo "\n";

$plain = new SoapClient($wsdl, [
    'location' => $resp,
    'uri' => 'http://example.com/echo',
    'trace' => 1,
]);
$plain->__soapCall('echo', [['input' => 'hi']]);
$h3 = $plain->__getLastRequestHeaders();
echo (is_string($h3) && !str_contains($h3, 'Accept-Encoding') && !str_contains($h3, 'Content-Encoding')) ? 'plain=1' : 'plain=0';
echo "\n";
