--TEST--
stdlib SoapClient keep_alive Connection header (#20364)
--FILE--
<?php
$wsdl = __DIR__ . '/test/fixtures/soap/echo.wsdl';
$resp = __DIR__ . '/test/fixtures/soap/echo.response.xml';
if (!is_file($wsdl)) {
    $wsdl = dirname(__DIR__, 3) . '/fixtures/soap/echo.wsdl';
    $resp = dirname(__DIR__, 3) . '/fixtures/soap/echo.response.xml';
}

$close = new SoapClient($wsdl, [
    'location' => $resp,
    'uri' => 'http://example.com/echo',
    'trace' => 1,
    'keep_alive' => false,
]);
$close->__soapCall('echo', [['input' => 'hi']]);
$h = $close->__getLastRequestHeaders();
echo (is_string($h) && str_contains($h, 'Connection: close')) ? 'close=1' : 'close=0';
echo "\n";
echo (is_string($h) && !str_contains($h, 'Connection: Keep-Alive')) ? 'no_ka=1' : 'no_ka=0';
echo "\n";

$keep = new SoapClient($wsdl, [
    'location' => $resp,
    'uri' => 'http://example.com/echo',
    'trace' => 1,
]);
$keep->__soapCall('echo', [['input' => 'hi']]);
$h2 = $keep->__getLastRequestHeaders();
echo (is_string($h2) && str_contains($h2, 'Connection: Keep-Alive')) ? 'default=1' : 'default=0';
echo "\n";

$zero = new SoapClient($wsdl, [
    'location' => $resp,
    'uri' => 'http://example.com/echo',
    'trace' => 1,
    'keep_alive' => 0,
]);
$zero->__soapCall('echo', [['input' => 'hi']]);
$h3 = $zero->__getLastRequestHeaders();
echo (is_string($h3) && str_contains($h3, 'Connection: close')) ? 'zero=1' : 'zero=0';
echo "\n";
?>
--EXPECT--
close=1
no_ka=1
default=1
zero=1
