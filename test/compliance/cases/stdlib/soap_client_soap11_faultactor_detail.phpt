--TEST--
stdlib SoapClient SOAP 1.1 faultactor/detail (#32048, php_packet_soap.c)
--FILE--
<?php
$fault11 = '<?xml version="1.0"?>'
    .'<SOAP-ENV:Envelope xmlns:SOAP-ENV="http://schemas.xmlsoap.org/soap/envelope/">'
    .'<SOAP-ENV:Body><SOAP-ENV:Fault>'
    .'<faultcode>SOAP-ENV:Server</faultcode>'
    .'<faultstring>boom11</faultstring>'
    .'<faultactor>http://example.com/actor</faultactor>'
    .'<detail><item>x</item></detail>'
    .'</SOAP-ENV:Fault></SOAP-ENV:Body></SOAP-ENV:Envelope>';
class Fault11ActorDetailClient extends SoapClient
{
    public string $fixture = '';
    public function __doRequest($request, $location, $action, $version, $oneWay = false): ?string
    {
        return $this->fixture;
    }
}
$c11 = new Fault11ActorDetailClient(null, [
    'location' => 'http://127.0.0.1/dummy',
    'uri' => 'http://example.com/',
    'soap_version' => SOAP_1_1,
    'exceptions' => true,
]);
$c11->fixture = $fault11;
try {
    $c11->__soapCall('echo', []);
    echo "s11_threw=0\n";
} catch (SoapFault $e) {
    echo "s11_threw=1\n";
    echo 's11_code=', (string) ($e->faultcode ?? ''), "\n";
    echo 's11_actor=', (string) ($e->faultactor ?? ''), "\n";
    $has = isset($e->detail) && null !== $e->detail;
    echo 's11_has_detail=', $has ? 1 : 0, "\n";
    $item = '';
    if ($has && is_object($e->detail) && isset($e->detail->item)) {
        $item = (string) $e->detail->item;
    }
    echo 's11_item=', $item, "\n";
}
?>
--EXPECT--
s11_threw=1
s11_code=SOAP-ENV:Server
s11_actor=http://example.com/actor
s11_has_detail=1
s11_item=x
