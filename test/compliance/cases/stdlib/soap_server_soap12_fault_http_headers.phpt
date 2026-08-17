--TEST--
stdlib SoapServer SOAP 1.2 fault HTTP 500 + application/soap+xml (#31957, ext/soap/soap.c)
--ENV--
GATEWAY_INTERFACE=CGI/1.1
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
$server->handle($req);
ob_end_clean();
$headers = headers_list();
echo 'status500=', (int) str_contains(implode("\n", $headers), '500 Internal Server Error'), "\n";
echo 'ct12=', (int) str_contains(implode("\n", $headers), 'application/soap+xml'), "\n";
echo 'no_text=', (int) !str_contains(implode("\n", $headers), 'text/xml; charset=utf-8'), "\n";
?>
--EXPECT--
status500=1
ct12=1
no_text=1
