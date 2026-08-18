--TEST--
stdlib SoapClient SOAP 1.2 Fault Detail → detail (#32047, php_packet_soap.c)
--FILE--
<?php
$fault12 = '<?xml version="1.0"?>'
    .'<env:Envelope xmlns:env="http://www.w3.org/2003/05/soap-envelope">'
    .'<env:Body><env:Fault>'
    .'<env:Code><env:Value>env:Receiver</env:Value></env:Code>'
    .'<env:Reason><env:Text>boom</env:Text></env:Reason>'
    .'<env:Detail><n:item xmlns:n="http://example.com/n">x</n:item></env:Detail>'
    .'</env:Fault></env:Body></env:Envelope>';
class FaultDetailClient extends SoapClient
{
    public string $fixture = '';
    public function __doRequest($request, $location, $action, $version, $oneWay = false): ?string
    {
        return $this->fixture;
    }
}
$c12 = new FaultDetailClient(null, [
    'location' => 'http://127.0.0.1/dummy',
    'uri' => 'http://example.com/',
    'soap_version' => SOAP_1_2,
    'exceptions' => true,
]);
$c12->fixture = $fault12;
try {
    $c12->__soapCall('echo', []);
    echo "s12_threw=0\n";
} catch (SoapFault $e) {
    echo "s12_threw=1\n";
    echo 's12_code=', (string) ($e->faultcode ?? ''), "\n";
    $has = isset($e->detail) && null !== $e->detail;
    echo 's12_has_detail=', $has ? 1 : 0, "\n";
    $item = '';
    if ($has && is_object($e->detail) && isset($e->detail->item)) {
        $item = (string) $e->detail->item;
    }
    echo 's12_item=', $item, "\n";
}
?>
--EXPECT--
s12_threw=1
s12_code=env:Receiver
s12_has_detail=1
s12_item=x
