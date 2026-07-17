--TEST--
stdlib SoapFault + is_soap_fault() (#20124, ext/soap/soap.c)
--FILE--
<?php
echo 'class=', class_exists('SoapFault') ? 1 : 0, "\n";
echo 'fn=', function_exists('is_soap_fault') ? 1 : 0, "\n";
$e = new SoapFault('Client', 'boom');
echo 'msg=', $e->getMessage(), "\n";
echo 'code=', $e->faultcode, "\n";
echo 'is=', is_soap_fault($e) ? 1 : 0, "\n";
echo 'not=', is_soap_fault(new Exception('x')) ? 1 : 0, "\n";
try {
    throw new SoapFault('Server', 'from_throw');
} catch (SoapFault $caught) {
    echo 'caught=', $caught->faultstring, "\n";
}
?>
--EXPECT--
class=1
fn=1
msg=boom
code=Client
is=1
not=0
caught=from_throw
