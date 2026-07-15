--TEST--
stdlib time(null) — ArgumentCountError not LogicException (#18744, ext/standard/basic_functions.c)
--FILE--
<?php
try {
    time(null);
    echo "uncaught\n";
} catch (ArgumentCountError $e) {
    echo $e->getMessage(), "\n";
}
?>
--EXPECT--
time() expects exactly 0 arguments, 1 given
