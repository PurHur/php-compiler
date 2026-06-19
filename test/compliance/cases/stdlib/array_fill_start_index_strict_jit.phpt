--TEST--
stdlib array_fill() start_index strict call-site int JIT (#9906, ext/standard/array.c)
--JIT--
--FILE--
<?php
declare(strict_types=1);
try {
    array_fill('0', 2, 'x');
    echo "no throw\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
var_export(array_fill(0, 2, 'x'));
echo "\n";
--EXPECT--
array_fill(): Argument #1 ($start_index) must be of type int, string given
array (
  0 => 'x',
  1 => 'x',
)
