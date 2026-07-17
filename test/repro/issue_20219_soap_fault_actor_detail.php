<?php
function boom($x) {
    global $server;
    $server->fault('Server', 'msg', 'http://actor', 'det');
    return 'never';
}
$server = new SoapServer(null, ['uri' => 'http://example.com/']);
$server->addFunction('boom');
$req = '<?xml version="1.0"?><SOAP-ENV:Envelope xmlns:SOAP-ENV="http://schemas.xmlsoap.org/soap/envelope/"'
    .' xmlns:ns1="http://example.com/"><SOAP-ENV:Body><ns1:boom><x>1</x></ns1:boom></SOAP-ENV:Body></SOAP-ENV:Envelope>';
ob_start();
$threw = 'no';
try { $server->handle($req); } catch (Throwable $e) { $threw = get_class($e); }
$out = (string) ob_get_clean();
echo 'THREW=', $threw, "\n";
echo 'HAS_FAULT=', str_contains($out, 'Fault') ? 1 : 0, "\n";
echo 'HAS_ACTOR=', (str_contains($out, 'faultactor') && str_contains($out, 'http://actor')) ? 1 : 0, "\n";
echo 'HAS_DETAIL=', (str_contains($out, 'detail') && str_contains($out, 'det')) ? 1 : 0, "\n";
