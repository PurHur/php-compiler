--TEST--
stdlib SoapClient WSDL binding style/use (#21132)
--FILE--
<?php
$base = __DIR__ . '/test/fixtures/soap';
if (!is_dir($base)) {
    $base = dirname(__DIR__, 3) . '/fixtures/soap';
}
$wsdl = $base . '/book.wsdl';
$resp = $base . '/book_no_xsi.response.xml';
$echoWsdl = $base . '/echo.wsdl';
$echoResp = $base . '/echo.response.xml';

$c = new SoapClient($wsdl, [
    'location' => $resp,
    'trace' => 1,
]);
$c->__soapCall('getBook', []);
$req = $c->__getLastRequest();
echo (strpos($req, 'encodingStyle=') === false) ? 'no_enc=1' : 'no_enc=0';
echo "\n";

$c2 = new SoapClient($echoWsdl, [
    'location' => $echoResp,
    'trace' => 1,
]);
$c2->__soapCall('echo', ['hello']);
$req2 = $c2->__getLastRequest();
echo (strpos($req2, 'encodingStyle=') !== false) ? 'echo_enc=1' : 'echo_enc=0';
echo "\n";
echo (strpos($req2, 'xsi:type=') !== false) ? 'echo_xsi=1' : 'echo_xsi=0';
echo "\n";
?>
--EXPECT--
no_enc=1
echo_enc=1
echo_xsi=1
