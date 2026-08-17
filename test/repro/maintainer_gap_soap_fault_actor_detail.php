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

function boom12($x) {
    global $server;
    $server->fault('Server', 'msg', 'http://actor', 'det');
    return 'never';
}
$server = new SoapServer(null, ['uri' => 'http://example.com/', 'soap_version' => SOAP_1_2]);
$server->addFunction('boom12');
$req12 = '<?xml version="1.0"?><env:Envelope xmlns:env="http://www.w3.org/2003/05/soap-envelope"'
    .' xmlns:ns1="http://example.com/"><env:Body><ns1:boom12><x>1</x></ns1:boom12></env:Body></env:Envelope>';
ob_start();
$threw12 = 'no';
try { $server->handle($req12); } catch (Throwable $e) { $threw12 = get_class($e); }
$out12 = (string) ob_get_clean();
echo 'THREW12=', $threw12, "\n";
echo 'ENV12=', str_contains($out12, '2003/05/soap-envelope') ? 1 : 0, "\n";
echo 'HAS_NODE=', str_contains($out12, 'Node') ? 1 : 0, "\n";
echo 'HAS_ROLE=', str_contains($out12, 'Role') ? 1 : 0, "\n";
echo 'HAS_ACTOR_URI12=', str_contains($out12, 'http://actor') ? 1 : 0, "\n";
echo 'HAS_ENV_DETAIL=', (str_contains($out12, 'Detail') && str_contains($out12, 'det')) ? 1 : 0, "\n";
