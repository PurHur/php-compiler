--TEST--
stdlib filter_input() — backed enum case variable name TypeError (#7204, ext/filter/filter.c)
--FILE--
<?php
enum K: string { case X = 'x'; }
try {
    filter_input(INPUT_GET, K::X, FILTER_DEFAULT);
    echo "uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
filter_input(): Argument #2 ($var_name) must be of type string, K given
