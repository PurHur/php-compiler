--TEST--
stdlib array_fill() numeric-string coercion and ValueError for negative count
--FILE--
<?php

var_export(array_fill('0', '2', 'x'));
echo "\n";

try {
    array_fill(0, -1, 1);
    echo "no throw\n";
} catch (ValueError $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
array (
  0 => 'x',
  1 => 'x',
)
array_fill(): Argument #2 ($count) must be greater than or equal to 0
