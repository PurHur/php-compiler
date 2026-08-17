--TEST--
stdlib SoapServer fault envelope includes SoapFault headerfault Header (#31958, ext/soap/soap.c)
--FILE--
<?php
function boom12($x)
{
    $hf = new SoapHeader('http://example.com/', 'FailHdr', 'hf-val');
    throw new SoapFault('Server', 'nope', null, null, null, $hf);
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
echo 'has_fault=', str_contains($out, 'Fault') ? 1 : 0, "\n";
echo 'has_header=', str_contains($out, 'Header') ? 1 : 0, "\n";
echo 'has_failhdr=', str_contains($out, 'FailHdr') ? 1 : 0, "\n";
echo 'has_hfval=', str_contains($out, 'hf-val') ? 1 : 0, "\n";

function boom11($x)
{
    $hf = new SoapHeader('http://example.com/', 'FailHdr11', 'hf11');
    throw new SoapFault('Server', 'nope11', null, null, null, $hf);
}

$server11 = new SoapServer(null, ['uri' => 'http://example.com/', 'soap_version' => SOAP_1_1]);
$server11->addFunction('boom11');
$req11 = '<?xml version="1.0"?><SOAP-ENV:Envelope xmlns:SOAP-ENV="http://schemas.xmlsoap.org/soap/envelope/"'
    .' xmlns:ns1="http://example.com/"><SOAP-ENV:Body><ns1:boom11><x>1</x></ns1:boom11></SOAP-ENV:Body></SOAP-ENV:Envelope>';
ob_start();
$server11->handle($req11);
$out11 = (string) ob_get_clean();
echo 'env11=', str_contains($out11, 'schemas.xmlsoap.org/soap/envelope') ? 1 : 0, "\n";
echo 'has_header11=', str_contains($out11, 'Header') ? 1 : 0, "\n";
echo 'has_failhdr11=', str_contains($out11, 'FailHdr11') ? 1 : 0, "\n";
?>
--EXPECT--
threw=no
env12=1
has_fault=1
has_header=1
has_failhdr=1
has_hfval=1
env11=1
has_header11=1
has_failhdr11=1
