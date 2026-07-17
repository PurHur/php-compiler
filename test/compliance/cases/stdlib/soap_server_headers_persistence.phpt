--TEST--
stdlib SoapServer addSoapHeader emission + setPersistence (#20186)
--FILE--
<?php
$req = '<?xml version="1.0" encoding="UTF-8"?>'
    .'<SOAP-ENV:Envelope xmlns:SOAP-ENV="http://schemas.xmlsoap.org/soap/envelope/"'
    .' xmlns:ns1="http://example.com/echo"'
    .' xmlns:xsd="http://www.w3.org/2001/XMLSchema"'
    .' xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"'
    .' SOAP-ENV:encodingStyle="http://schemas.xmlsoap.org/soap/encoding/">'
    .'<SOAP-ENV:Body><ns1:bump><input xsi:type="xsd:int">1</input></ns1:bump></SOAP-ENV:Body>'
    .'</SOAP-ENV:Envelope>';

class CounterSvc
{
    public int $n = 0;
    public function bump($input)
    {
        $this->n += (int) $input;
        return $this->n;
    }
}

$server = new SoapServer(null, ['uri' => 'http://example.com/echo']);
$server->setPersistence(SOAP_PERSISTENCE_SESSION);
$server->setClass('CounterSvc');
$server->addSoapHeader(new SoapHeader('http://example.com/meta', 'Stamp', 'v1'));
ob_start();
$server->handle($req);
$out1 = ob_get_clean();
echo 'hdr=', (is_string($out1) && str_contains($out1, 'Stamp') && str_contains($out1, 'v1')) ? 1 : 0, "\n";
echo 'n1=', (is_string($out1) && str_contains($out1, '>1</')) ? 1 : 0, "\n";
ob_start();
$server->handle($req);
$out2 = ob_get_clean();
echo 'n2=', (is_string($out2) && str_contains($out2, '>2</')) ? 1 : 0, "\n";

$s2 = new SoapServer(null, ['uri' => 'http://example.com/echo']);
$s2->setPersistence(SOAP_PERSISTENCE_REQUEST);
$s2->setClass('CounterSvc');
ob_start();
$s2->handle($req);
$a = ob_get_clean();
ob_start();
$s2->handle($req);
$b = ob_get_clean();
echo 'req=', (is_string($a) && is_string($b) && str_contains($a, '>1</') && str_contains($b, '>1</')) ? 1 : 0, "\n";
?>
--EXPECT--
hdr=1
n1=1
n2=1
req=1
