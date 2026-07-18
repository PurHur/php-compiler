--TEST--
stdlib SoapServer SOAP_PERSISTENCE_SESSION encode/decode round-trip (#20342)
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
$n1 = (is_string($o1) && preg_match('/<return[^>]*>(\d+)<\/return>/', $o1, $m)) ? (int) $m[1] : -1;
$enc = session_encode();
$_SESSION = [];
$decoded = session_decode($enc);
$obj = $_SESSION['_bogus_session_name'] ?? null;
$nprop = is_object($obj) ? (int) $obj->n : -99;

$b = new SoapServer(null, ['uri' => 'http://example.com/echo']);
$b->setPersistence(SOAP_PERSISTENCE_SESSION);
$b->setClass('CounterSvc');
ob_start();
$b->handle($req);
$o2 = ob_get_clean();
$n2 = (is_string($o2) && preg_match('/<return[^>]*>(\d+)<\/return>/', $o2, $m2)) ? (int) $m2[1] : -1;

echo 'n1=', $n1, "\n";
echo 'decoded=', $decoded ? 1 : 0, "\n";
echo 'nprop=', $nprop, "\n";
echo 'n2=', $n2, "\n";
?>
--EXPECT--
n1=1
decoded=1
nprop=1
n2=2
