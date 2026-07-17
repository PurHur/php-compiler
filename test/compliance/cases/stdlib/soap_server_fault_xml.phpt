--TEST--
stdlib SoapServer::fault during handle emits SOAP Fault XML (#20194, ext/soap/soap.c)
--FILE--
<?php
function boom($x)
{
    global $server;
    $server->fault('Server', 'nope');
    return 'never';
}

class FaultSvc
{
    public function boom($x)
    {
        global $server;
        $server->fault('Server', 'obj-nope');
        return 'never';
    }
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
echo 'fn_threw=', $threw, "\n";
echo 'fn_fault=', (str_contains($out, 'SOAP-ENV:Fault') && str_contains($out, 'Server') && str_contains($out, 'nope')) ? 1 : 0, "\n";
echo 'fn_resp=', str_contains($out, 'boomResponse') ? 1 : 0, "\n";
$last = $server->__getLastResponse();
echo 'fn_last=', (is_string($last) && str_contains($last, 'SOAP-ENV:Fault')) ? 1 : 0, "\n";

$server2 = new SoapServer(null, ['uri' => 'http://example.com/']);
$server2->setObject(new FaultSvc());
$server = $server2;
ob_start();
$threw2 = 'no';
try {
    $server2->handle($req);
} catch (Throwable $e) {
    $threw2 = get_class($e);
}
$out2 = (string) ob_get_clean();
echo 'obj_threw=', $threw2, "\n";
echo 'obj_fault=', (str_contains($out2, 'SOAP-ENV:Fault') && str_contains($out2, 'obj-nope')) ? 1 : 0, "\n";

$outside = 'no';
try {
    $server->fault('Server', 'outside');
} catch (Throwable $e) {
    $outside = get_class($e);
}
echo 'outside=', $outside, "\n";
?>
--EXPECT--
fn_threw=no
fn_fault=1
fn_resp=0
fn_last=1
obj_threw=no
obj_fault=1
outside=SoapFault
