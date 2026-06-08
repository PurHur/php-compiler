--TEST--
Stdlib: ReflectionParameter::getValue() unwraps #[\SensitiveParameter] values (#5127)
--FILE--
<?php
function f(#[\SensitiveParameter] string $secret) {}
$r = new ReflectionFunction('f');
$p = $r->getParameters()[0];
$v = $p->getValue(['secret' => 'pw']);
var_export($v);
echo "\n";
var_export(get_debug_type($v));
echo "\n";
$wrapped = new SensitiveParameterValue('hidden');
$v2 = $p->getValue(['secret' => $wrapped]);
var_export($v2);
echo "\n";
$attrs = $p->getAttributes('SensitiveParameter');
echo count($attrs), "\n";
echo $attrs[0]->getName();
--EXPECT--
'pw'
'string'
'hidden'
1
SensitiveParameter
