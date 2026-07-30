--TEST--
stdlib Reflection stubs: array_replace optional variadic; restore_error_handler true; range int|float step; dirname levels int (#25480)
--FILE--
<?php
$r = new ReflectionFunction('array_replace');
$p = $r->getParameters()[1];
echo 'replacements name=', $p->getName(),
    ' opt=', (int) $p->isOptional(),
    ' variadic=', (int) $p->isVariadic(),
    ' type=', $p->hasType() ? (string) $p->getType() : 'NONE',
    ' required=', $r->getNumberOfRequiredParameters(),
    "\n";
var_export(array_replace([1]));
echo "\n";

$r = new ReflectionFunction('restore_error_handler');
echo 'restore ret=', $r->hasReturnType() ? (string) $r->getReturnType() : 'none', "\n";

$r = new ReflectionFunction('range');
$p = $r->getParameters()[2];
echo 'step type=', $p->hasType() ? (string) $p->getType() : 'none', "\n";
echo json_encode(range(0, 1, 0.5)), "\n";

$r = new ReflectionFunction('dirname');
$p = $r->getParameters()[1];
echo 'levels type=', $p->hasType() ? (string) $p->getType() : 'none',
    ' def=', $p->isDefaultValueAvailable() ? var_export($p->getDefaultValue(), true) : '-',
    "\n";
echo 'dirname=', dirname('/a/b/c'), "\n";
?>
--EXPECT--
replacements name=replacements opt=1 variadic=1 type=array required=1
array (
  0 => 1,
)
restore ret=true
step type=int|float
[0,0.5,1]
levels type=int def=1
dirname=/a/b
