--TEST--
stdlib array_unshift/array_merge/array_multisort — empty call-time unpack arity (#6690, ext/standard/array.c)
--FILE--
<?php
$a = array(1);
echo array_unshift($a, ...array()), "\n";
var_export($a);
echo "\n";
var_export(array_merge(...array()));
echo "\n";
try {
    array_multisort(...array());
    echo "multisort: uncaught\n";
} catch (ArgumentCountError $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
1
array (
  0 => 1,
)
array (
)
array_multisort() expects at least 1 argument, 0 given
