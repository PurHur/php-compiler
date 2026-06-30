--TEST--
stdlib parse_str() one-arg at script scope throws ArgumentCountError (#14112, ext/standard/basic_functions.c)
--FILE--
<?php
try {
    parse_str('route=home&page=3');
    echo "no throw\n";
} catch (ArgumentCountError $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
parse_str() expects exactly 2 arguments, 1 given
