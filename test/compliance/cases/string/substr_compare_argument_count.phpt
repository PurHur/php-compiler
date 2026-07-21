--TEST--
string substr_compare() wrong argc — ArgumentCountError not LogicException (#21769, ext/standard/string.c)
--FILE--
<?php
foreach ([2, 6] as $n) {
    try {
        if (2 === $n) {
            substr_compare(null, 'a');
        } else {
            substr_compare('a', 'b', 0, null, false, 'extra');
        }
        echo "uncaught_$n\n";
    } catch (ArgumentCountError $e) {
        echo $e->getMessage(), "\n";
    }
}
?>
--EXPECT--
substr_compare() expects at least 3 arguments, 2 given
substr_compare() expects at most 5 arguments, 6 given
