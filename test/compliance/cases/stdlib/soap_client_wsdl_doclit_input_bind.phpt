--TEST--
stdlib SoapClient WSDL input sequence names positional args (#21131)
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
    'trace' => 1,
]);
$c->__soapCall('echo', ['hello']);
$req = $c->__getLastRequest();
echo (strpos($req, '<input') !== false && strpos($req, '>hello<') !== false) ? 'input=1' : 'input=0';
echo "\n";
echo (strpos($req, '<param0') !== false) ? 'param0=1' : 'param0=0';
echo "\n";
?>
--EXPECT--
input=1
param0=0
