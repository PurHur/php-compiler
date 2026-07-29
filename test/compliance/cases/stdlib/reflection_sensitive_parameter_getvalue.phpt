--TEST--
Stdlib: #[\SensitiveParameter] visible via ReflectionParameter::getAttributes (#5127); getValue phantom removed (#25057)
--FILE--
<?php
function f(#[\SensitiveParameter] string $secret) {}
$r = new ReflectionFunction('f');
$p = $r->getParameters()[0];
echo method_exists($p, 'getValue') ? "getvalue=1\n" : "getvalue=0\n";
$attrs = $p->getAttributes('SensitiveParameter');
echo count($attrs), "\n";
echo $attrs[0]->getName();
echo "\n";
$sp = new SensitiveParameterValue('hidden');
echo 'sp=', $sp->getValue(), "\n";
--EXPECT--
getvalue=0
1
SensitiveParameter
sp=hidden
