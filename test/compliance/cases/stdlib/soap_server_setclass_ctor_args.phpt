--TEST--
stdlib SoapServer::setClass() forwards constructor args (#20294, ext/soap/soap.c)
--FILE--
<?php
class Svc
{
    public $n;
    public function __construct($n = 0)
    {
        $this->n = $n;
    }
    public function get()
    {
        return $this->n;
    }
}

$req = '<?xml version="1.0" encoding="UTF-8"?>'
    .'<SOAP-ENV:Envelope xmlns:SOAP-ENV="http://schemas.xmlsoap.org/soap/envelope/"'
    .' xmlns:ns1="http://example.com/"'
    .' xmlns:xsd="http://www.w3.org/2001/XMLSchema"'
    .' xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"'
    .' SOAP-ENV:encodingStyle="http://schemas.xmlsoap.org/soap/encoding/">'
    .'<SOAP-ENV:Body><ns1:get/></SOAP-ENV:Body>'
    .'</SOAP-ENV:Envelope>';

$server = new SoapServer(null, ['uri' => 'http://example.com/']);
$server->setClass('Svc', 42);
ob_start();
$server->handle($req);
$out = ob_get_clean();
echo 'has_42=', (is_string($out) && str_contains($out, '42')) ? 1 : 0, "\n";
echo 'has_resp=', (is_string($out) && str_contains($out, 'getResponse')) ? 1 : 0, "\n";
?>
--EXPECT--
has_42=1
has_resp=1
