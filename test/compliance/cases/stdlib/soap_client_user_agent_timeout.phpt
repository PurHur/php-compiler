--TEST--
stdlib SoapClient user_agent / connection_timeout ctor options (#20341)
--FILE--
<?php
$wsdl = __DIR__ . '/test/fixtures/soap/echo.wsdl';
$resp = __DIR__ . '/test/fixtures/soap/echo.response.xml';
if (!is_file($wsdl)) {
    $wsdl = dirname(__DIR__, 3) . '/fixtures/soap/echo.wsdl';
    $resp = dirname(__DIR__, 3) . '/fixtures/soap/echo.response.xml';
}

$c = new SoapClient($wsdl, [
    'location' => $resp,
    'uri' => 'http://example.com/echo',
    'trace' => 1,
    'user_agent' => 'MySoapBot/1.0',
    'connection_timeout' => 7,
]);
$c->__soapCall('echo', [['input' => 'hi']]);
$h = $c->__getLastRequestHeaders();
echo (is_string($h) && str_contains($h, 'User-Agent: MySoapBot/1.0')) ? 'ua=1' : 'ua=0';
echo "\n";
echo (is_string($h) && !str_contains($h, 'User-Agent: PHP-SOAP/')) ? 'no_default=1' : 'no_default=0';
echo "\n";

$d = new SoapClient($wsdl, [
    'location' => $resp,
    'uri' => 'http://example.com/echo',
    'trace' => 1,
]);
$d->__soapCall('echo', [['input' => 'hi']]);
$h2 = $d->__getLastRequestHeaders();
echo (is_string($h2) && str_contains($h2, 'User-Agent: PHP-SOAP/')) ? 'default=1' : 'default=0';
echo "\n";
?>
--EXPECT--
ua=1
no_default=1
default=1
