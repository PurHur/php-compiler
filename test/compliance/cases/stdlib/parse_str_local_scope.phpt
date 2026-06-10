--TEST--
stdlib parse_str() zero-arg throws ArgumentCountError (#4050)
--FILE--
<?php
try {
    parse_str();
    echo "no throw\n";
} catch (ArgumentCountError $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
parse_str() expects exactly 2 arguments, 0 given
