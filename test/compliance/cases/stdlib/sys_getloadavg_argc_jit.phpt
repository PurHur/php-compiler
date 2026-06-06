--TEST--
stdlib sys_getloadavg() JIT — ArgumentCountError for extra args
--FILE--
<?php
try {
    sys_getloadavg(1);
    echo "no throw\n";
} catch (ArgumentCountError $e) {
    echo $e->getMessage(), "\n";
}
?>
--EXPECT--
sys_getloadavg() expects exactly 0 arguments, 1 given
