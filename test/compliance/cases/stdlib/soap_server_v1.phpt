--TEST--
stdlib SoapServer v1 setObject/handle string request (#20126, ext/soap/soap.c)
--FILE--
<?php
echo 'class=', class_exists('SoapServer') ? 1 : 0, "\n";

class EchoSvc
{
    public function echo($input)
    {
        return $input;
    }
}

$req = '<?xml version="1.0" encoding="UTF-8"?>'
    .'<SOAP-ENV:Envelope xmlns:SOAP-ENV="http://schemas.xmlsoap.org/soap/envelope/"'
    .' xmlns:ns1="http://example.com/echo"'
    .' xmlns:xsd="http://www.w3.org/2001/XMLSchema"'
    .' xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"'
    .' SOAP-ENV:encodingStyle="http://schemas.xmlsoap.org/soap/encoding/">'
    .'<SOAP-ENV:Body><ns1:echo><input xsi:type="xsd:string">hello</input></ns1:echo></SOAP-ENV:Body>'
    .'</SOAP-ENV:Envelope>';

$server = new SoapServer(null, ['uri' => 'http://example.com/echo']);
$server->setObject(new EchoSvc());
$fns = $server->getFunctions();
echo 'has_echo=', (is_array($fns) && in_array('echo', $fns, true)) ? 1 : 0, "\n";
ob_start();
$server->handle($req);
$out = ob_get_clean();
echo 'has_hello=', (is_string($out) && str_contains($out, 'hello')) ? 1 : 0, "\n";
echo 'has_resp=', (is_string($out) && str_contains($out, 'echoResponse')) ? 1 : 0, "\n";
$last = $server->__getLastResponse();
echo 'last_ok=', (is_string($last) && str_contains($last, 'hello')) ? 1 : 0, "\n";
?>
--EXPECT--
class=1
has_echo=1
has_hello=1
has_resp=1
last_ok=1
