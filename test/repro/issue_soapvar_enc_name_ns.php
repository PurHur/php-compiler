<?php
/**
 * Repro #32191 — SoapVar enc_name / enc_namens (php-src php_encoding.c xmlNodeSetName / xmlSetNs).
 */
declare(strict_types=1);

if (!class_exists('SoapClient')) {
    echo "soap_unavailable\n";
    exit(0);
}

$resp = dirname(__DIR__).'/fixtures/soap/echo.response.xml';
$c = new SoapClient(null, [
    'location' => $resp,
    'uri' => 'http://example.com/echo',
    'trace' => 1,
    'exceptions' => false,
    'style' => SOAP_RPC,
    'use' => SOAP_ENCODED,
]);

$c->__soapCall('echo', [new SoapVar('hello', XSD_STRING, null, null, 'input')]);
$n = (string) $c->__getLastRequest();
echo 'name_only=', str_contains($n, '<input xsi:type="xsd:string">hello</input>') ? '1' : '0', "\n";
echo 'no_param0=', str_contains($n, 'param0') ? '0' : '1', "\n";

$c->__soapCall('echo', [new SoapVar('hello', XSD_STRING, null, null, 'input', 'http://example.com/echo')]);
$ns = (string) $c->__getLastRequest();
echo 'ns1_input=', str_contains($ns, '<ns1:input xsi:type="xsd:string">hello</ns1:input>') ? '1' : '0', "\n";
