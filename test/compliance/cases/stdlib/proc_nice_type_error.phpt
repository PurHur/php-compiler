--TEST--
stdlib proc_nice() — TypeError on array priority (#5181)
--FILE--
<?php
try {
    proc_nice([]);
    echo "no\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
proc_nice(): Argument #1 ($priority) must be of type int, array given
