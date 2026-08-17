<?php
/**
 * Repro #31944 — SoapServer::fault SOAP 1.2 env:Code/Reason envelope
 * (php-src ext/soap/soap.c serialize_response_call SOAP_1_2 + set_soap_fault).
 *
 * Zend 8.2: SOAP 1.2 envelope; fault('Receiver') Value is unprefixed;
 * fault('Server') Value is env:Receiver. No SOAP 1.1 Fault/faultcode.
 */
function boom12($x)
{
    global $server;
    $server->fault('Receiver', 'nope12');
    return 'never';
}

$server = new SoapServer(null, ['uri' => 'http://example.com/', 'soap_version' => SOAP_1_2]);
$server->addFunction('boom12');
$req = '<?xml version="1.0"?><env:Envelope xmlns:env="http://www.w3.org/2003/05/soap-envelope"'
    .' xmlns:ns1="http://example.com/"><env:Body><ns1:boom12><x>1</x></ns1:boom12></env:Body></env:Envelope>';
ob_start();
$threw = 'no';
try {
    $server->handle($req);
} catch (Throwable $e) {
    $threw = get_class($e);
}
$out = (string) ob_get_clean();
echo 'THREW=', $threw, "\n";
echo 'ENV12=', str_contains($out, '2003/05/soap-envelope') ? 1 : 0, "\n";
echo 'ENV11=', str_contains($out, 'schemas.xmlsoap.org/soap/envelope') ? 1 : 0, "\n";
echo 'HAS_FAULT=', str_contains($out, 'Fault') ? 1 : 0, "\n";
echo 'HAS_CODE=', str_contains($out, 'Code') ? 1 : 0, "\n";
echo 'HAS_REASON=', str_contains($out, 'Reason') ? 1 : 0, "\n";
echo 'VALUE_BARE=', str_contains($out, '<env:Value>Receiver</env:Value>') ? 1 : 0, "\n";
echo 'VALUE_QN=', str_contains($out, '<env:Value>env:Receiver</env:Value>') ? 1 : 0, "\n";
echo 'HAS_FAULTCODE=', str_contains($out, 'faultcode') ? 1 : 0, "\n";
echo 'HAS_LANG=', str_contains($out, 'xml:lang') ? 1 : 0, "\n";

function boom12s($x)
{
    global $server;
    $server->fault('Server', 'nope12s');
    return 'never';
}
$server = new SoapServer(null, ['uri' => 'http://example.com/', 'soap_version' => SOAP_1_2]);
$server->addFunction('boom12s');
$reqS = str_replace('boom12', 'boom12s', $req);
ob_start();
$server->handle($reqS);
$outS = (string) ob_get_clean();
echo 'SERVER_QN=', str_contains($outS, '<env:Value>env:Receiver</env:Value>') ? 1 : 0, "\n";
echo 'SERVER_BARE=', str_contains($outS, '<env:Value>Receiver</env:Value>') ? 1 : 0, "\n";
