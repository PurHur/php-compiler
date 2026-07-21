--TEST--
string addcslashes() wrong argc — ArgumentCountError not LogicException (#21756, ext/standard/string.c)
--FILE--
<?php
foreach ([0, 1, 3] as $n) {
    try {
        if (0 === $n) {
            addcslashes();
        } elseif (1 === $n) {
            addcslashes('x');
        } else {
            addcslashes('a', 'b', 'c');
        }
        echo "uncaught_$n\n";
    } catch (ArgumentCountError $e) {
        echo $e->getMessage(), "\n";
    }
}
?>
--EXPECT--
addcslashes() expects exactly 2 arguments, 0 given
addcslashes() expects exactly 2 arguments, 1 given
addcslashes() expects exactly 2 arguments, 3 given
