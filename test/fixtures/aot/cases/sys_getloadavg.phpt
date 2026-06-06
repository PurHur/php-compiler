--TEST--
AOT: sys_getloadavg() returns three load averages or false
--SKIPIF--
<?php
if (!function_exists('sys_getloadavg') || false === sys_getloadavg()) {
    die('skip sys_getloadavg unavailable on host');
}
?>
--FILE--
<?php
$avg = sys_getloadavg();
if (false === $avg) {
    echo "false\n";
} else {
    echo count($avg) === 3 ? "three\n" : "bad_count\n";
}
?>
--EXPECT--
three
