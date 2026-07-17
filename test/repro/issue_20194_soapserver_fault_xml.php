<?php
/**
 * Repro #20194 — SoapServer::fault during handle emits SOAP Fault XML (php-src ext/soap/soap.c).
 */
function boom($x)
{
    global $server;
    $server->fault('Server', 'nope');

    return 'never';
}

$server = new SoapServer(null, ['uri' => 'http://example.com/']);
$server->addFunction('boom');
$req = '<?xml version="1.0"?><SOAP-ENV:Envelope xmlns:SOAP-ENV="http://schemas.xmlsoap.org/soap/envelope/"'
    .' xmlns:ns1="http://example.com/"><SOAP-ENV:Body><ns1:boom><x>1</x></ns1:boom></SOAP-ENV:Body>'
    .'</SOAP-ENV:Envelope>';
ob_start();
$threw = 'no';
try {
    $server->handle($req);
} catch (Throwable $e) {
    $threw = get_class($e).':'.$e->getMessage();
}
$out = (string) ob_get_clean();
echo 'THREW=', $threw, "\n";
echo 'HAS_FAULT=', (str_contains($out, 'SOAP-ENV:Fault') || str_contains($out, ':Fault>')) ? '1' : '0', "\n";
echo 'HAS_CODE=', str_contains($out, '>Server</') ? '1' : '0', "\n";
echo 'HAS_STR=', str_contains($out, 'nope') ? '1' : '0', "\n";
echo 'HAS_RESP=', str_contains($out, 'boomResponse') ? '1' : '0', "\n";

$outside = 'no';
try {
    $server->fault('Server', 'outside');
} catch (Throwable $e) {
    $outside = get_class($e);
}
echo 'OUTSIDE=', $outside, "\n";
