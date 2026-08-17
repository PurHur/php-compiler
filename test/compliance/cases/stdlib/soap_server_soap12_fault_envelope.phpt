--TEST--
stdlib SoapServer::fault SOAP 1.2 env:Code/Reason; Receiver unprefixed (#31944, ext/soap/soap.c)
--FILE--
<?php
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
echo 'threw=', $threw, "\n";
echo 'env12=', str_contains($out, '2003/05/soap-envelope') ? 1 : 0, "\n";
echo 'env11=', str_contains($out, 'schemas.xmlsoap.org/soap/envelope') ? 1 : 0, "\n";
echo 'code=', str_contains($out, '<env:Code>') ? 1 : 0, "\n";
echo 'reason=', str_contains($out, '<env:Reason>') ? 1 : 0, "\n";
echo 'bare=', str_contains($out, '<env:Value>Receiver</env:Value>') ? 1 : 0, "\n";
echo 'qn=', str_contains($out, '<env:Value>env:Receiver</env:Value>') ? 1 : 0, "\n";
echo 'faultcode=', str_contains($out, 'faultcode') ? 1 : 0, "\n";

function boom12s($x)
{
    global $server;
    $server->fault('Server', 'nope12s');
    return 'never';
}
$server = new SoapServer(null, ['uri' => 'http://example.com/', 'soap_version' => SOAP_1_2]);
$server->addFunction('boom12s');
ob_start();
$server->handle(str_replace('boom12', 'boom12s', $req));
$outS = (string) ob_get_clean();
echo 'server_qn=', str_contains($outS, '<env:Value>env:Receiver</env:Value>') ? 1 : 0, "\n";
?>
--EXPECT--
threw=no
env12=1
env11=0
code=1
reason=1
bare=1
qn=0
faultcode=0
server_qn=1
