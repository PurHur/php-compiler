<?php
/**
 * Repro #32045 — SoapClient SOAP 1.2 Fault Code/Value only (not concatenated
 * Subcode). php-src ext/soap/php_packet_soap.c parse_packet_soap SOAP 1.2:
 * get_node(Code) then get_node(Value).
 *
 * Zend 8.2: faultcode=env:Receiver
 * VM before fix: env:Receiverapp:Specific
 */
declare(strict_types=1);

if (!class_exists('SoapClient')) {
    echo "soap_unavailable\n";
    exit(0);
}

$fault12 = '<?xml version="1.0"?>'
    .'<env:Envelope xmlns:env="http://www.w3.org/2003/05/soap-envelope">'
    .'<env:Body><env:Fault>'
    .'<env:Code><env:Value>env:Receiver</env:Value>'
    .'<env:Subcode><env:Value>app:Specific</env:Value></env:Subcode></env:Code>'
    .'<env:Reason><env:Text xml:lang="en">boom-en</env:Text></env:Reason>'
    .'</env:Fault></env:Body></env:Envelope>';

$fault11 = '<?xml version="1.0"?>'
    .'<SOAP-ENV:Envelope xmlns:SOAP-ENV="http://schemas.xmlsoap.org/soap/envelope/">'
    .'<SOAP-ENV:Body><SOAP-ENV:Fault>'
    .'<faultcode>SOAP-ENV:Server</faultcode>'
    .'<faultstring>boom11</faultstring>'
    .'</SOAP-ENV:Fault></SOAP-ENV:Body></SOAP-ENV:Envelope>';

class FaultCodeValueClient extends SoapClient
{
    public string $fixture = '';

    public function __doRequest($request, $location, $action, $version, $oneWay = false): ?string
    {
        return $this->fixture;
    }
}

function dumpCode(string $label, FaultCodeValueClient $c): void
{
    try {
        $c->__soapCall('echo', []);
        echo $label, '_threw=0', "\n";
    } catch (SoapFault $e) {
        $code = (string) ($e->faultcode ?? '');
        echo $label, '_threw=1', "\n";
        echo $label, '_code=', $code, "\n";
        echo $label, '_value_only=', ('env:Receiver' === $code) ? 1 : 0, "\n";
        echo $label, '_has_subcode=', str_contains($code, 'Specific') ? 1 : 0, "\n";
    }
}

$c12 = new FaultCodeValueClient(null, [
    'location' => 'http://127.0.0.1/dummy',
    'uri' => 'http://example.com/',
    'soap_version' => SOAP_1_2,
    'exceptions' => true,
    'trace' => 1,
]);
$c12->fixture = $fault12;
dumpCode('s12', $c12);

$c11 = new FaultCodeValueClient(null, [
    'location' => 'http://127.0.0.1/dummy',
    'uri' => 'http://example.com/',
    'soap_version' => SOAP_1_1,
    'exceptions' => true,
    'trace' => 1,
]);
$c11->fixture = $fault11;
try {
    $c11->__soapCall('echo', []);
    echo "s11_threw=0\n";
} catch (SoapFault $e) {
    echo 's11_threw=1', "\n";
    echo 's11_code=', (string) ($e->faultcode ?? ''), "\n";
}
