--TEST--
stdlib crypt() wrong argc — ArgumentCountError not LogicException (#20975, ext/standard/crypt.c)
--FILE--
<?php
foreach ([0, 1, 3] as $n) {
    try {
        if (0 === $n) {
            crypt();
        } elseif (1 === $n) {
            crypt('secret');
        } else {
            crypt('secret', 'xx', 'extra');
        }
        echo "uncaught_$n\n";
    } catch (ArgumentCountError $e) {
        echo $e->getMessage(), "\n";
    }
}
?>
--EXPECT--
crypt() expects exactly 2 arguments, 0 given
crypt() expects exactly 2 arguments, 1 given
crypt() expects exactly 2 arguments, 3 given
