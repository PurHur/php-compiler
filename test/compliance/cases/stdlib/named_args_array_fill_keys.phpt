--TEST--
array_fill_keys named keys:/value: arguments (VM, issue #23490)
--FILE--
<?php
$rf = new ReflectionFunction('array_fill_keys');
foreach ($rf->getParameters() as $p) {
    echo 'param:', $p->getName(), PHP_EOL;
}
var_export(array_fill_keys(keys: ['a', 'b'], value: 1));
echo PHP_EOL;
var_export(array_fill_keys(['x'], value: 2));
echo PHP_EOL;
try {
    array_fill_keys(keys: ['a'], val: 1);
    echo "val accepted\n";
} catch (Throwable $e) {
    echo 'val:', $e->getMessage(), PHP_EOL;
}
--EXPECT--
param:keys
param:value
array (
  'a' => 1,
  'b' => 1,
)
array (
  'x' => 2,
)
val:Unknown named parameter $val
