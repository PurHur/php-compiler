--TEST--
stdlib SoapClient::__soapCall $options location/soapaction (#31873)
--FILE--
<?php
$wsdl = __DIR__ . '/test/fixtures/soap/echo.wsdl';
$resp = __DIR__ . '/test/fixtures/soap/echo.response.xml';
if (!is_file($wsdl)) {
    $wsdl = dirname(__DIR__, 3) . '/fixtures/soap/echo.wsdl';
    $resp = dirname(__DIR__, 3) . '/fixtures/soap/echo.response.xml';
}
$wrong = $wsdl;
$r = new ReflectionMethod('SoapClient', '__soapCall');
$names = [];
foreach ($r->getParameters() as $p) {
    $t = $p->getType();
    $names[] = $p->getName().':'.(null !== $t ? (string) $t : '').($p->isOptional() ? '=' : '');
}
echo 'params=', implode(',', $names), "\n";
$client = new SoapClient($wsdl, [
    'location' => $wrong,
    'uri' => 'http://example.com/echo',
    'trace' => 1,
    'exceptions' => false,
]);
$ret = $client->__soapCall('echo', [['input' => 'hello']], ['location' => $resp]);
echo 'opt_loc=', (is_string($ret) && $ret === 'hello') ? 'hello' : gettype($ret), "\n";
$rp = new ReflectionProperty('SoapClient', 'location');
$rp->setAccessible(true);
echo 'sticky=', ($rp->getValue($client) === $wrong) ? '1' : '0', "\n";
$c2 = new SoapClient(null, [
    'location' => $resp,
    'uri' => 'http://example.com/echo',
    'trace' => 1,
    'exceptions' => false,
]);
$c2->__soapCall('echo', [['input' => 'hello']], ['soapaction' => 'urn:custom-action']);
echo 'action=', str_contains((string) $c2->__getLastRequestHeaders(), 'urn:custom-action') ? '1' : '0', "\n";
$c2->__soapCall('echo', [['input' => 'hello']], ['uri' => 'urn:other']);
echo 'uri=', str_contains((string) $c2->__getLastRequest(), 'urn:other') ? '1' : '0', "\n";
?>
--EXPECT--
params=name:string,args:array,options:?array=,inputHeaders:=,outputHeaders:=
opt_loc=hello
sticky=1
action=1
uri=1
