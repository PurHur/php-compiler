--TEST--
stdlib SoapFault array code / faultcodens SOAP 1.2 Code QName (#31956, ext/soap/soap.c)
--FILE--
<?php
$probe = new SoapFault(['http://example.com/app', 'AppError'], 'nope');
echo 'prop_code=', $probe->faultcode === 'AppError' ? 1 : 0, "\n";
echo 'prop_ns=', (isset($probe->faultcodens) && $probe->faultcodens === 'http://example.com/app') ? 1 : 0, "\n";

function boom12($x)
{
    throw new SoapFault(['http://example.com/app', 'AppError'], 'nope');
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
echo 'apperror=', str_contains($out, 'AppError') ? 1 : 0, "\n";
echo 'app_ns=', str_contains($out, 'http://example.com/app') ? 1 : 0, "\n";
echo 'qn=', str_contains($out, '<env:Value>ns1:AppError</env:Value>') ? 1 : 0, "\n";
echo 'bare=', str_contains($out, '<env:Value>AppError</env:Value>') ? 1 : 0, "\n";
echo 'faultcode=', str_contains($out, 'faultcode') ? 1 : 0, "\n";
?>
--EXPECT--
prop_code=1
prop_ns=1
threw=no
env12=1
apperror=1
app_ns=1
qn=1
bare=0
faultcode=0
