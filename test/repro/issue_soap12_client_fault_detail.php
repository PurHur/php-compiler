<?php
/**
 * Repro #32047 — SoapClient SOAP 1.2 Fault Detail → SoapFault::$detail.
 * php-src ext/soap/php_packet_soap.c: master_to_zval(Detail).
 *
 * Zend 8.2: detail is stdClass{item: "x"}
 * VM before fix: detail unset
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
    'trace' => 1,
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
