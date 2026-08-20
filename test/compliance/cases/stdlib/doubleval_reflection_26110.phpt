--TEST--
stdlib doubleval Reflection mixed $value): float + named value (#26110, type.stub.php)
--FILE--
<?php
$r = new ReflectionFunction('doubleval');
echo 'params=', $r->getNumberOfParameters(), "\n";
foreach ($r->getParameters() as $p) {
    echo 'param=[', $p, "]\n";
}
echo 'return=', $r->hasReturnType() ? (string) $r->getReturnType() : '(none)', "\n";
var_export(doubleval(value: '3.5'));
echo "\n";
var_export(doubleval('3.5'));
echo "\n";
try {
    doubleval(var: '3.5');
    echo "legacy var ok\n";
} catch (Throwable $e) {
    echo 'legacy=', get_class($e), ': ', $e->getMessage(), "\n";
}
?>
--EXPECT--
params=1
param=[Parameter #0 [ <required> mixed $value ]]
return=float
3.5
3.5
legacy=Error: Unknown named parameter $var
