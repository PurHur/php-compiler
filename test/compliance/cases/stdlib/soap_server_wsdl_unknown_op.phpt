--TEST--
stdlib SoapServer WSDL mode rejects unknown operations (#20314)
--FILE--
<?php
$wsdl = __DIR__ . '/test/fixtures/soap/echo.wsdl';
if (!is_file($wsdl)) {
    $wsdl = dirname(__DIR__, 3) . '/fixtures/soap/echo.wsdl';
}

class EchoSvc
{
    public function echo($input)
    {
        return $input;
    }

    public function notAnOp($input)
    {
        return 'should-not-run';
    }
}

$okReq = '<?xml version="1.0" encoding="UTF-8"?>'
    .'<SOAP-ENV:Envelope xmlns:SOAP-ENV="http://schemas.xmlsoap.org/soap/envelope/"'
    .' xmlns:ns1="http://example.com/echo"'
    .' xmlns:xsd="http://www.w3.org/2001/XMLSchema"'
    .' xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"'
    .' SOAP-ENV:encodingStyle="http://schemas.xmlsoap.org/soap/encoding/">'
    .'<SOAP-ENV:Body><ns1:echo><input xsi:type="xsd:string">hello</input></ns1:echo></SOAP-ENV:Body>'
    .'</SOAP-ENV:Envelope>';

$badReq = '<?xml version="1.0" encoding="UTF-8"?>'
    .'<SOAP-ENV:Envelope xmlns:SOAP-ENV="http://schemas.xmlsoap.org/soap/envelope/"'
    .' xmlns:ns1="http://example.com/echo"'
    .' xmlns:xsd="http://www.w3.org/2001/XMLSchema"'
    .' xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"'
    .' SOAP-ENV:encodingStyle="http://schemas.xmlsoap.org/soap/encoding/">'
    .'<SOAP-ENV:Body><ns1:notAnOp><input xsi:type="xsd:string">x</input></ns1:notAnOp></SOAP-ENV:Body>'
    .'</SOAP-ENV:Envelope>';

$server = new SoapServer($wsdl, ['uri' => 'http://example.com/echo']);
$server->setClass('EchoSvc');

ob_start();
$server->handle($okReq);
$ok = ob_get_clean();
echo (is_string($ok) && str_contains($ok, 'hello') && !str_contains($ok, 'Fault')) ? 'ok=1' : 'ok=0';
echo "\n";

ob_start();
$server->handle($badReq);
$bad = ob_get_clean();
echo (is_string($bad) && str_contains($bad, 'Fault') && str_contains($bad, "doesn't exist")) ? 'bad=1' : 'bad=0';
echo "\n";
?>
--EXPECT--
ok=1
bad=1
