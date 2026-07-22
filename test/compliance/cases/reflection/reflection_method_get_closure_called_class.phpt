--TEST--
ReflectionMethod::getClosureCalledClass() (#22166)
--FILE--
<?php
class Outer { public function make() { return function () { return 1; }; } }
$rm = new ReflectionMethod(Outer::class, 'make');
echo method_exists($rm, 'getClosureCalledClass') ? 'yes' : 'no', "\n";
var_export($rm->getClosureCalledClass());
echo "\n";
$o = new Outer();
$rf = new ReflectionFunction($o->make());
$cc = $rf->getClosureCalledClass();
echo null === $cc ? 'null' : $cc->getName(), "\n";
?>
--EXPECT--
yes
NULL
Outer
