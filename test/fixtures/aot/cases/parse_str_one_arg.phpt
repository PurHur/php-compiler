--TEST--
AOT: parse_str() one-arg throws ArgumentCountError (#14112)
--FILE--
<?php
function t(): void {
    try {
        parse_str('a=1&b=2');
        echo "no throw\n";
    } catch (ArgumentCountError $e) {
        echo $e->getMessage(), "\n";
    }
}
t();
--EXPECT--
parse_str() expects exactly 2 arguments, 1 given
