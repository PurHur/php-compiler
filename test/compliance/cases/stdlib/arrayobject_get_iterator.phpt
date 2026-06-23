--TEST--
stdlib ArrayObject::getIterator() and ArrayAccess offsets (#10639, ext/spl/spl_array.c)
--FILE--
<?php
$ao = new ArrayObject(['a' => 1, 'b' => 2]);
var_export($ao['a']);
echo "\n";
$ao['c'] = 3;
var_export(isset($ao['b']));
echo "\n";
unset($ao['a']);
var_export(array_key_exists('a', (array) $ao->getArrayCopy()));
echo "\n";
$it = $ao->getIterator();
var_export($it instanceof ArrayIterator);
echo "\n";
$keys = [];
foreach ($it as $k => $v) {
    $keys[] = $k . ':' . $v;
}
sort($keys);
var_export($keys);
echo "\n";
--EXPECT--
1
true
false
true
array (
  0 => 'b:2',
  1 => 'c:3',
)
