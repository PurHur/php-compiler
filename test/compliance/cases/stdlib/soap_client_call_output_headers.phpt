--TEST--
stdlib SoapClient::__soapCall &$outputHeaders SOAP Header children (#31875)
--FILE--
<?php
$wsdl = __DIR__ . '/test/fixtures/soap/echo.wsdl';
$resp = __DIR__ . '/test/fixtures/soap/echo.response.xml';
$respHdr = __DIR__ . '/test/fixtures/soap/echo.response.with_header.xml';
if (!is_file($wsdl)) {
    $wsdl = dirname(__DIR__, 3) . '/fixtures/soap/echo.wsdl';
    $resp = dirname(__DIR__, 3) . '/fixtures/soap/echo.response.xml';
    $respHdr = dirname(__DIR__, 3) . '/fixtures/soap/echo.response.with_header.xml';
}
$r = new ReflectionMethod('SoapClient', '__soapCall');
$p = $r->getParameters();
$outP = $p[4] ?? null;
echo 'byref=', ($outP && $outP->isPassedByReference()) ? '1' : '0', "\n";
$client = new SoapClient($wsdl, [
    'location' => $resp,
    'uri' => 'http://example.com/echo',
    'trace' => 1,
    'exceptions' => false,
]);
$out = ['sentinel' => 1];
$client->__soapCall('echo', [['input' => 'hello']], null, null, $out);
echo 'cleared=', (is_array($out) && !array_key_exists('sentinel', $out)) ? '1' : '0', "\n";
$clientH = new SoapClient($wsdl, [
    'location' => $respHdr,
    'uri' => 'http://example.com/echo',
    'exceptions' => false,
]);
$hdrs = [];
$clientH->__soapCall('echo', [['input' => 'hello']], null, null, $hdrs);
echo 'Token=', (isset($hdrs['Token']) && $hdrs['Token'] === 'secret') ? 'secret' : 'missing', "\n";
?>
--EXPECT--
byref=1
cleared=1
Token=secret
