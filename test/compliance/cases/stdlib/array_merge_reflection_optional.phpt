--TEST--
stdlib array_merge Reflection optional variadic (#25382, ext/standard/array.stub.php)
--FILE--
<?php
$r = new ReflectionFunction('array_merge');
$p = $r->getParameters()[0];
echo 'name=', $p->getName(),
    ' opt=', (int) $p->isOptional(),
    ' variadic=', (int) $p->isVariadic(),
    ' type=', $p->hasType() ? (string) $p->getType() : 'NONE',
    ' required=', $r->getNumberOfRequiredParameters(),
    ' argc=', $r->getNumberOfParameters(),
    "\n";
var_export(array_merge());
echo "\n";
var_export(array_merge([1]));
echo "\n";
?>
--EXPECT--
name=arrays opt=1 variadic=1 type=array required=0 argc=1
array (
)
array (
  0 => 1,
)
