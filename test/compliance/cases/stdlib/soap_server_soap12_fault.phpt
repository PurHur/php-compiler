--TEST--
stdlib SoapServer SOAP_1_2 Fault envelope (#20221, ext/soap/soap.c)
--FILE--
<?php
function boom12($x)
{
    global $server;
    $server->fault('Server', 'nope12');
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
echo 'code=', (str_contains($out, '<env:Code>') && str_contains($out, '<env:Value>env:Receiver</env:Value>')) ? 1 : 0, "\n";
echo 'reason=', (str_contains($out, '<env:Reason>') && str_contains($out, 'nope12')) ? 1 : 0, "\n";

// SOAP 1.1 path unchanged
function boom11($x)
{
    global $server;
    $server->fault('Server', 'nope11');
    return 'never';
}
$server = new SoapServer(null, ['uri' => 'http://example.com/']);
$server->addFunction('boom11');
$req11 = str_replace('boom12', 'boom11', str_replace('2003/05/soap-envelope', 'schemas.xmlsoap.org/soap/envelope', str_replace('env:', 'SOAP-ENV:', $req)));
ob_start();
$server->handle($req11);
$out11 = (string) ob_get_clean();
echo 'v11=', (str_contains($out11, 'faultcode') && str_contains($out11, 'schemas.xmlsoap.org/soap/envelope')) ? 1 : 0, "\n";
?>
--EXPECT--
threw=no
env12=1
env11=0
code=1
reason=1
v11=1
