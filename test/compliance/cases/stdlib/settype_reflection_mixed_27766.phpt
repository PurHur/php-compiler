--TEST--
stdlib settype Reflection mixed &$var + bool return (#27766, basic_functions.stub.php)
--FILE--
<?php
$r = new ReflectionFunction('settype');
echo 'params=', $r->getNumberOfParameters(), "\n";
foreach ($r->getParameters() as $p) {
    echo 'param=[', $p, "]\n";
}
echo 'return=', $r->hasReturnType() ? (string) $r->getReturnType() : '(none)', "\n";
$x = '3';
var_export(settype(var: $x, type: 'int'));
echo "\n";
var_export($x);
echo "\n";
?>
--EXPECT--
params=2
param=[Parameter #0 [ <required> mixed &$var ]]
param=[Parameter #1 [ <required> string $type ]]
return=bool
true
3
