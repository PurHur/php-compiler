--TEST--
stdlib array_column() — enum case column_key TypeError (#5974, ext/standard/array.c)
--FILE--
<?php
enum E: string { case A = 'n'; }
try {
    array_column([['n' => 1]], E::A);
    echo "uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
array_column(): Argument #2 ($column_key) must be of type string|int|null, E given
