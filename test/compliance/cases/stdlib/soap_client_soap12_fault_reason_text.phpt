--TEST--
stdlib SoapClient SOAP 1.2 Fault first Reason/Text only (#32046, php_packet_soap.c)
--FILE--
<?php
$fault12 = '<?xml version="1.0"?>'
    .'<env:Envelope xmlns:env="http://www.w3.org/2003/05/soap-envelope">'
    .'<env:Body><env:Fault>'
    .'<env:Code><env:Value>env:Receiver</env:Value></env:Code>'
    .'<env:Reason><env:Text xml:lang="en">boom-en</env:Text>'
    .'<env:Text xml:lang="fr">boom-fr</env:Text></env:Reason>'
    .'</env:Fault></env:Body></env:Envelope>';
$fault11 = '<?xml version="1.0"?>'
    .'<SOAP-ENV:Envelope xmlns:SOAP-ENV="http://schemas.xmlsoap.org/soap/envelope/">'
    .'<SOAP-ENV:Body><SOAP-ENV:Fault>'
    .'<faultcode>SOAP-ENV:Server</faultcode>'
    .'<faultstring>boom11</faultstring>'
    .'</SOAP-ENV:Fault></SOAP-ENV:Body></SOAP-ENV:Envelope>';
class FaultReasonTextClient extends SoapClient
{
    public string $fixture = '';
    public function __doRequest($request, $location, $action, $version, $oneWay = false): ?string
    {
        return $this->fixture;
    }
}
$c12 = new FaultReasonTextClient(null, [
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
    $str = (string) ($e->faultstring ?? '');
    echo "s12_threw=1\n";
    echo 's12_str=', $str, "\n";
    echo 's12_first_text=', ('boom-en' === $str) ? 1 : 0, "\n";
    echo 's12_has_fr=', str_contains($str, 'boom-fr') ? 1 : 0, "\n";
}
$c11 = new FaultReasonTextClient(null, [
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
    echo 's11_str=', (string) ($e->faultstring ?? ''), "\n";
}
?>
--EXPECT--
s12_threw=1
s12_str=boom-en
s12_first_text=1
s12_has_fr=0
s11_threw=1
s11_str=boom11
