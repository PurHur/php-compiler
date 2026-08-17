--TEST--
stdlib SoapClient SOAP 1.2 Content-Type application/soap+xml (#31918)
--FILE--
<?php
$resp = __DIR__ . '/test/fixtures/soap/echo.response.xml';
if (!is_file($resp)) {
    $resp = dirname(__DIR__, 3) . '/fixtures/soap/echo.response.xml';
}
$c12 = new SoapClient(null, [
    'location' => $resp,
    'uri' => 'http://example.com/echo',
    'trace' => 1,
    'exceptions' => false,
    'soap_version' => SOAP_1_2,
]);
$c12->__soapCall('echo', [['input' => 'hello']]);
$h12 = (string) $c12->__getLastRequestHeaders();
echo 'ct12=', str_contains($h12, 'application/soap+xml') ? '1' : '0', "\n";
echo 'action=', str_contains($h12, 'action=') ? '1' : '0', "\n";
echo 'no_sa=', str_contains($h12, 'SOAPAction:') ? '0' : '1', "\n";
$c11 = new SoapClient(null, [
    'location' => $resp,
    'uri' => 'http://example.com/echo',
    'trace' => 1,
    'exceptions' => false,
    'soap_version' => SOAP_1_1,
]);
$c11->__soapCall('echo', [['input' => 'hello']]);
$h11 = (string) $c11->__getLastRequestHeaders();
echo 'ct11=', str_contains($h11, 'text/xml') ? '1' : '0', "\n";
echo 'sa11=', str_contains($h11, 'SOAPAction:') ? '1' : '0', "\n";
?>
--EXPECT--
ct12=1
action=1
no_sa=1
ct11=1
sa11=1
