--TEST--
stdlib SoapClient login/password → Authorization Basic (#20312)
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
?>
--EXPECT--
auth=1
b64=1
none=1
