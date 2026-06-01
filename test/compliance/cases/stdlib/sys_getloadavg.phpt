--TEST--
stdlib sys_getloadavg() returns three load averages or false
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
    $t0 = gettype($avg[0]);
    $t1 = gettype($avg[1]);
    $t2 = gettype($avg[2]);
    echo ('double' === $t0 || 'integer' === $t0) && ('double' === $t1 || 'integer' === $t1) && ('double' === $t2 || 'integer' === $t2) ? "numeric\n" : "bad_types\n";
}
?>
--EXPECT--
three
numeric
