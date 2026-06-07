--TEST--
Language: new without constructor returns object instance (#6467)
--FILE--
<?php
class C {}

echo (new C() === null ? 'null' : 'obj'), "\n";
echo (new stdClass() === null ? 'null' : 'obj'), "\n";
echo (new Exception('x') instanceof Exception ? 'ex' : 'no'), "\n";

$o = new stdClass();
$o->x = 42;
echo $o->x, "\n";

$c = new C();
var_export($c instanceof C);
echo "\n";
--EXPECT--
obj
obj
ex
42
true
