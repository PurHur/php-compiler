--TEST--
Stdlib: is_soap_fault() JIT lowering (#26167, ext/soap/soap.c)
--FILE--
<?php
declare(strict_types=1);

$e = new SoapFault('Client', 'boom');
echo 'is=', is_soap_fault($e) ? 1 : 0, "\n";
echo 'not=', is_soap_fault(new Exception('x')) ? 1 : 0, "\n";
echo 'null=', is_soap_fault(null) ? 1 : 0, "\n";
echo 'int=', is_soap_fault(1) ? 1 : 0, "\n";
?>
--EXPECT--
is=1
not=0
null=0
int=0
