--TEST--
ReflectionClass::getInterfaces() returns name => ReflectionClass map (#22170)
--FILE--
<?php
interface I {}
interface J extends I {}
class C implements J {}

$r = new ReflectionClass(C::class);
echo 'method=', method_exists($r, 'getInterfaces') ? '1' : '0', "\n";
$ifaces = $r->getInterfaces();
echo 'keys=', json_encode(array_keys($ifaces)), "\n";
echo 'J=', $ifaces['J']->getName(), "\n";
echo 'I=', $ifaces['I']->getName(), "\n";
echo 'JisRC=', ($ifaces['J'] instanceof ReflectionClass) ? '1' : '0', "\n";
echo 'J_keys=', json_encode(array_keys((new ReflectionClass(J::class))->getInterfaces())), "\n";
echo 'I_keys=', json_encode(array_keys((new ReflectionClass(I::class))->getInterfaces())), "\n";
?>
--EXPECT--
method=1
keys=["J","I"]
J=J
I=I
JisRC=1
J_keys=["I"]
I_keys=[]
