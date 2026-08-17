--TEST--
stdlib SoapServer SOAP 1.1 fault HTTP 500 + text/xml (#31957, ext/soap/soap.c)
--ENV--
GATEWAY_INTERFACE=CGI/1.1
--FILE--
<?php
function boom11($x)
{
    global $server;
    $server->fault('Server', 'nope11');
    return 'never';
}
$server = new SoapServer(null, ['uri' => 'http://example.com/', 'soap_version' => SOAP_1_1]);
$server->addFunction('boom11');
$req = '<?xml version="1.0"?><SOAP-ENV:Envelope xmlns:SOAP-ENV="http://schemas.xmlsoap.org/soap/envelope/"'
    .' xmlns:ns1="http://example.com/"><SOAP-ENV:Body><ns1:boom11><x>1</x></ns1:boom11></SOAP-ENV:Body></SOAP-ENV:Envelope>';
ob_start();
$server->handle($req);
ob_end_clean();
$headers = headers_list();
echo 'status500=', (int) str_contains(implode("\n", $headers), '500 Internal Server Error'), "\n";
echo 'ct11=', (int) str_contains(implode("\n", $headers), 'text/xml; charset=utf-8'), "\n";
echo 'no_soapxml=', (int) !str_contains(implode("\n", $headers), 'application/soap+xml'), "\n";
?>
--EXPECT--
status500=1
ct11=1
no_soapxml=1
