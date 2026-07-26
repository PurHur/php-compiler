--TEST--
stdlib filter_input() — backed enum case variable name TypeError JIT (#7204)
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
