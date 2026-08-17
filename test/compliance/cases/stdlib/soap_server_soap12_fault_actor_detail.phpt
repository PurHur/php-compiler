--TEST--
stdlib SoapServer::fault SOAP 1.2 env:Detail; actor omitted from XML (#31945, ext/soap/soap.c)
--FILE--
<?php
function boom12($x)
{
    global $server;
    $server->fault('Server', 'msg', 'http://actor', 'det');
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
echo 'threw=', $threw, "\n";
echo 'env12=', str_contains($out, '2003/05/soap-envelope') ? 1 : 0, "\n";
echo 'env11=', str_contains($out, 'schemas.xmlsoap.org/soap/envelope') ? 1 : 0, "\n";
echo 'receiver=', str_contains($out, 'Receiver') ? 1 : 0, "\n";
echo 'node=', str_contains($out, 'Node') ? 1 : 0, "\n";
echo 'role=', str_contains($out, 'Role') ? 1 : 0, "\n";
echo 'actor_uri=', str_contains($out, 'http://actor') ? 1 : 0, "\n";
echo 'detail=', (str_contains($out, 'Detail') && str_contains($out, 'det')) ? 1 : 0, "\n";
echo 'detail11=', str_contains($out, '<detail>') ? 1 : 0, "\n";

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
env12=1
env11=0
receiver=1
node=0
role=0
actor_uri=0
detail=1
detail11=0
outside=http://out-actor,out-det
