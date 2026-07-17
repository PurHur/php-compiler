<?php
function boom12($x) {
    global $server;
    $server->fault('Receiver', 'nope12');
    return 'never';
}
$server = new SoapServer(null, ['uri' => 'http://example.com/', 'soap_version' => SOAP_1_2]);
$server->addFunction('boom12');
$req = '<?xml version="1.0"?><env:Envelope xmlns:env="http://www.w3.org/2003/05/soap-envelope"'
    .' xmlns:ns1="http://example.com/"><env:Body><ns1:boom12><x>1</x></ns1:boom12></env:Body></env:Envelope>';
ob_start();
try { $server->handle($req); } catch (Throwable $e) { echo 'THREW=', get_class($e), "\n"; }
$out = (string) ob_get_clean();
echo 'ENV12=', str_contains($out, '2003/05/soap-envelope') ? 1 : 0, "\n";
echo 'ENV11=', str_contains($out, 'schemas.xmlsoap.org/soap/envelope') ? 1 : 0, "\n";
echo 'HAS_FAULT=', str_contains($out, 'Fault') ? 1 : 0, "\n";
