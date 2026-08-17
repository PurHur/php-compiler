--TEST--
stdlib SoapServer SOAP 1.2 success envelope + Content-Type (#31921, #31957)
--ENV--
GATEWAY_INTERFACE=CGI/1.1
--FILE--
<?php
function echo12($x) {
    return $x;
}
$server = new SoapServer(null, ['uri' => 'http://example.com/', 'soap_version' => SOAP_1_2]);
$server->addFunction('echo12');
$req = '<?xml version="1.0"?><env:Envelope xmlns:env="http://www.w3.org/2003/05/soap-envelope"'
    .' xmlns:ns1="http://example.com/"><env:Body><ns1:echo12><x>hi</x></ns1:echo12></env:Body></env:Envelope>';
ob_start();
$server->handle($req);
$out = (string) ob_get_clean();
$headers = headers_list();
echo 'ENV12=', str_contains($out, '2003/05/soap-envelope') ? '1' : '0', "\n";
echo 'ENC12=', str_contains($out, '2003/05/soap-encoding') ? '1' : '0', "\n";
echo 'env_prefix=', str_contains($out, '<env:Envelope') ? '1' : '0', "\n";
echo 'ct12=', (int) str_contains(implode("\n", $headers), 'application/soap+xml'), "\n";
?>
--EXPECT--
ENV12=1
ENC12=1
env_prefix=1
ct12=1
