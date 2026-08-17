<?php
/**
 * Repro #31958 — SoapFault headerfault in SOAP 1.2 fault envelope Header (ext/soap/soap.c).
 */
declare(strict_types=1);

function boom12($x)
{
    $hf = new SoapHeader('http://example.com/', 'FailHdr', 'hf-val');
    throw new SoapFault('Server', 'nope', null, null, null, $hf);
}

$server = new SoapServer(null, ['uri' => 'http://example.com/', 'soap_version' => SOAP_1_2]);
$server->addFunction('boom12');
$req = '<?xml version="1.0"?><env:Envelope xmlns:env="http://www.w3.org/2003/05/soap-envelope"'
    .' xmlns:ns1="http://example.com/"><env:Body><ns1:boom12><x>1</x></ns1:boom12></env:Body></env:Envelope>';
ob_start();
$server->handle($req);
$out = (string) ob_get_clean();
echo 'ENV12=', str_contains($out, '2003/05/soap-envelope') ? 1 : 0, "\n";
echo 'HAS_FAULT=', str_contains($out, 'Fault') ? 1 : 0, "\n";
echo 'HAS_HEADER=', str_contains($out, 'Header') ? 1 : 0, "\n";
echo 'HAS_FAILHDR=', str_contains($out, 'FailHdr') ? 1 : 0, "\n";

function boom11($x)
{
    $hf = new SoapHeader('http://example.com/', 'FailHdr11', 'hf11');
    throw new SoapFault('Server', 'nope11', null, null, null, $hf);
}

$server11 = new SoapServer(null, ['uri' => 'http://example.com/', 'soap_version' => SOAP_1_1]);
$server11->addFunction('boom11');
$req11 = '<?xml version="1.0"?><SOAP-ENV:Envelope xmlns:SOAP-ENV="http://schemas.xmlsoap.org/soap/envelope/"'
    .' xmlns:ns1="http://example.com/"><SOAP-ENV:Body><ns1:boom11><x>1</x></ns1:boom11></SOAP-ENV:Body></SOAP-ENV:Envelope>';
ob_start();
$server11->handle($req11);
$out11 = (string) ob_get_clean();
echo 'ENV11=', str_contains($out11, 'schemas.xmlsoap.org/soap/envelope') ? 1 : 0, "\n";
echo 'HAS_HEADER11=', str_contains($out11, 'Header') ? 1 : 0, "\n";
echo 'HAS_FAILHDR11=', str_contains($out11, 'FailHdr11') ? 1 : 0, "\n";
