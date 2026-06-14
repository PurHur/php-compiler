--TEST--
stdlib array_sum()/array_column() — TypeError when first argument is not array (#4504, ext/standard/array.c)
--FILE--
<?php
foreach ([
    'array_sum' => fn() => array_sum('x'),
    'array_column_null' => fn() => array_column(null, 'k'),
] as $name => $fn) {
    try {
        $fn();
    } catch (Throwable $e) {
        echo "$name: ", get_class($e), ': ', $e->getMessage(), "\n";
    }
    echo "$name: done\n";
}
--EXPECT--
array_sum: TypeError: array_sum(): Argument #1 ($array) must be of type array, string given
array_sum: done
array_column_null: TypeError: array_column(): Argument #1 ($array) must be of type array, null given
array_column_null: done
