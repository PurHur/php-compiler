--TEST--
stdlib SoapClient ssl_method option E_DEPRECATED (#20366)
--FILE--
<?php
$wsdl = __DIR__ . '/test/fixtures/soap/echo.wsdl';
$resp = __DIR__ . '/test/fixtures/soap/echo.response.xml';
if (!is_file($wsdl)) {
    $wsdl = dirname(__DIR__, 3) . '/fixtures/soap/echo.wsdl';
    $resp = dirname(__DIR__, 3) . '/fixtures/soap/echo.response.xml';
}

$seen = [];
set_error_handler(static function (int $errno, string $errstr) use (&$seen): bool {
    if ($errno === E_DEPRECATED) {
        $seen[] = $errstr;
    }

    return true;
});

$c = new SoapClient($wsdl, [
    'location' => $resp,
    'uri' => 'http://example.com/echo',
    'trace' => 1,
    'ssl_method' => SOAP_SSL_METHOD_TLS,
]);
restore_error_handler();

$hit = false;
foreach ($seen as $msg) {
    if (str_contains($msg, 'ssl_method') && str_contains($msg, 'deprecated')) {
        $hit = true;
        break;
    }
}
echo $hit ? 'deprecated=1' : 'deprecated=0';
echo "\n";

$c->__soapCall('echo', [['input' => 'hi']]);
$h = $c->__getLastRequestHeaders();
echo (is_string($h) && str_contains($h, 'POST ')) ? 'call=1' : 'call=0';
echo "\n";
echo defined('SOAP_SSL_METHOD_TLS') ? 'const=1' : 'const=0';
echo "\n";
?>
--EXPECT--
deprecated=1
call=1
const=1
