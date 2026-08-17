--TEST--
stdlib SoapClient SOAP 1.2 Header role/mustUnderstand (#31920)
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
$c12->__setSoapHeaders(new SoapHeader('urn:h', 'Auth', 'tok', true, 'http://example.com/role'));
$c12->__soapCall('echo', [['input' => 'hello']]);
$req12 = (string) $c12->__getLastRequest();
echo 'role=', str_contains($req12, 'role=') ? '1' : '0', "\n";
echo 'no_actor=', str_contains($req12, 'actor=') ? '0' : '1', "\n";
echo 'mu_true=', str_contains($req12, 'mustUnderstand="true"') ? '1' : '0', "\n";
$c11 = new SoapClient(null, [
    'location' => $resp,
    'uri' => 'http://example.com/echo',
    'trace' => 1,
    'exceptions' => false,
    'soap_version' => SOAP_1_1,
]);
$c11->__setSoapHeaders(new SoapHeader('urn:h', 'Auth', 'tok', true, 'http://example.com/actor'));
$c11->__soapCall('echo', [['input' => 'hello']]);
$req11 = (string) $c11->__getLastRequest();
echo 'actor11=', str_contains($req11, 'actor=') ? '1' : '0', "\n";
echo 'mu1_11=', str_contains($req11, 'mustUnderstand="1"') ? '1' : '0', "\n";
?>
--EXPECT--
role=1
no_actor=1
mu_true=1
actor11=1
mu1_11=1
