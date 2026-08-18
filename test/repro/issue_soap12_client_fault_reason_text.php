<?php
/**
 * Repro #32046 — SoapClient SOAP 1.2 Fault first Reason/Text only (not
 * concatenated Text nodes). php-src ext/soap/php_packet_soap.c:
 * get_node(Reason) then get_node(Text).
 *
 * Zend 8.2: faultstring=boom-en
 * VM before fix: boom-enboom-fr
 */
declare(strict_types=1);

if (!class_exists('SoapClient')) {
    echo "soap_unavailable\n";
    exit(0);
}

$fault12 = '<?xml version="1.0"?>'
    .'<env:Envelope xmlns:env="http://www.w3.org/2003/05/soap-envelope">'
    .'<env:Body><env:Fault>'
    .'<env:Code><env:Value>env:Receiver</env:Value></env:Code>'
    .'<env:Reason><env:Text xml:lang="en">boom-en</env:Text>'
    .'<env:Text xml:lang="fr">boom-fr</env:Text></env:Reason>'
    .'</env:Fault></env:Body></env:Envelope>';

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
    'trace' => 1,
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

$fault11 = '<?xml version="1.0"?>'
    .'<SOAP-ENV:Envelope xmlns:SOAP-ENV="http://schemas.xmlsoap.org/soap/envelope/">'
    .'<SOAP-ENV:Body><SOAP-ENV:Fault>'
    .'<faultcode>SOAP-ENV:Server</faultcode>'
    .'<faultstring>boom11</faultstring>'
    .'</SOAP-ENV:Fault></SOAP-ENV:Body></SOAP-ENV:Envelope>';
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
