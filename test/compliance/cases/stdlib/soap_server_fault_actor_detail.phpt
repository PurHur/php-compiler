--TEST--
stdlib SoapServer::fault actor/details/name in Fault XML (#20219, ext/soap/soap.c)
--FILE--
<?php
function boom($x)
{
    global $server;
    $server->fault('Server', 'msg', 'http://actor', 'det');
    return 'never';
}

function boomNamed($x)
{
    global $server;
    $server->fault('Server', 'msg2', 'http://a2', 'payload', 'Item');
    return 'never';
}

$req = '<?xml version="1.0"?><SOAP-ENV:Envelope xmlns:SOAP-ENV="http://schemas.xmlsoap.org/soap/envelope/"'
    .' xmlns:ns1="http://example.com/"><SOAP-ENV:Body><ns1:boom><x>1</x></ns1:boom></SOAP-ENV:Body>'
    .'</SOAP-ENV:Envelope>';

$server = new SoapServer(null, ['uri' => 'http://example.com/']);
$server->addFunction('boom');
ob_start();
$threw = 'no';
try {
    $server->handle($req);
} catch (Throwable $e) {
    $threw = get_class($e);
}
$out = (string) ob_get_clean();
echo 'threw=', $threw, "\n";
echo 'fault=', str_contains($out, 'SOAP-ENV:Fault') ? 1 : 0, "\n";
echo 'actor=', (str_contains($out, 'faultactor') && str_contains($out, 'http://actor')) ? 1 : 0, "\n";
echo 'detail=', (str_contains($out, '<detail>') && str_contains($out, 'det')) ? 1 : 0, "\n";

$req2 = str_replace('boom', 'boomNamed', $req);
$server = new SoapServer(null, ['uri' => 'http://example.com/']);
$server->addFunction('boomNamed');
ob_start();
$server->handle($req2);
$out2 = (string) ob_get_clean();
echo 'named=', (str_contains($out2, '<Item>') && str_contains($out2, 'payload')) ? 1 : 0, "\n";

$outside = 'no';
try {
    $server->fault('Server', 'out', 'http://out-actor', 'out-det');
} catch (SoapFault $e) {
    $outside = (string) $e->faultactor.','.(string) $e->detail;
}
echo 'outside=', $outside, "\n";
?>
--EXPECT--
threw=no
fault=1
actor=1
detail=1
named=1
outside=http://out-actor,out-det
