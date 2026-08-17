--TEST--
stdlib SoapClient::__doRequest override + oneWay SOAP_WAIT_ONE_WAY_CALLS (#31876)
--FILE--
<?php
$wsdl = __DIR__ . '/test/fixtures/soap/echo.wsdl';
$resp = __DIR__ . '/test/fixtures/soap/echo.response.xml';
if (!is_file($wsdl)) {
    $wsdl = dirname(__DIR__, 3) . '/fixtures/soap/echo.wsdl';
    $resp = dirname(__DIR__, 3) . '/fixtures/soap/echo.response.xml';
}
class ProbeDoRequest extends SoapClient
{
    public int $hits = 0;
    public mixed $lastOneWay = 'unset';
    public function __doRequest($request, $location, $action, $version, $oneWay = false): ?string
    {
        $this->lastOneWay = $oneWay;
        ++$this->hits;
        return parent::__doRequest($request, $location, $action, $version, $oneWay);
    }
}
$c = new ProbeDoRequest($wsdl, [
    'location' => $resp,
    'uri' => 'http://example.com/echo',
    'exceptions' => false,
]);
$c->__soapCall('echo', [['input' => 'hello']]);
echo 'hits=', $c->hits, "\n";
echo 'ow=', $c->lastOneWay === false ? '0' : '1', "\n";
$plain = new SoapClient($wsdl, [
    'location' => $resp,
    'uri' => 'http://example.com/echo',
    'exceptions' => false,
]);
echo 'empty=', ($plain->__doRequest('<x/>', $resp, 'urn:x', SOAP_1_1, true) === '') ? '1' : '0', "\n";
?>
--EXPECT--
hits=1
ow=0
empty=1
