--TEST--
stdlib SoapServer SOAP_PERSISTENCE_SESSION via $_SESSION (#20315)
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

session_start();

$a = new SoapServer(null, ['uri' => 'http://example.com/echo']);
$a->setPersistence(SOAP_PERSISTENCE_SESSION);
$a->setClass('CounterSvc');
ob_start();
$a->handle($req);
$o1 = ob_get_clean();
echo 'n1=', (is_string($o1) && str_contains($o1, '>1</')) ? 1 : 0, "\n";

$b = new SoapServer(null, ['uri' => 'http://example.com/echo']);
$b->setPersistence(SOAP_PERSISTENCE_SESSION);
$b->setClass('CounterSvc');
ob_start();
$b->handle($req);
$o2 = ob_get_clean();
echo 'n2=', (is_string($o2) && str_contains($o2, '>2</')) ? 1 : 0, "\n";
echo 'has_key=', isset($_SESSION['_bogus_session_name']) ? 1 : 0, "\n";

$c = new SoapServer(null, ['uri' => 'http://example.com/echo']);
$c->setPersistence(SOAP_PERSISTENCE_REQUEST);
$c->setClass('CounterSvc');
ob_start();
$c->handle($req);
$a1 = ob_get_clean();
ob_start();
$c->handle($req);
$a2 = ob_get_clean();
echo 'req_mode=', (is_string($a1) && is_string($a2) && str_contains($a1, '>1</') && str_contains($a2, '>1</')) ? 1 : 0, "\n";
?>
--EXPECT--
n1=1
n2=1
has_key=1
req_mode=1
