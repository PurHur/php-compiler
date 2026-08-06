--TEST--
stdlib array_is_list() after unset+restore contiguous keys (#28051)
--FILE--
<?php
$a = [0 => 1, 1 => 2];
unset($a[1]);
$a[1] = 3;
echo array_is_list($a) ? "restore_list\n" : "bad_restore\n";
echo count($a) === 2 ? "restore_count\n" : "bad_count\n";

$b = [1 => 1, 2 => 2];
echo array_is_list($b) ? "bad_nonzero\n" : "nonzero_start\n";

$c = [0 => 1, 1 => 2, 2 => 3];
unset($c[1]);
$c[1] = 9;
echo array_is_list($c) ? "bad_middle\n" : "middle_not_list\n";
echo count($c) === 3 ? "middle_count\n" : "bad_middle_count\n";

$d = [0 => 1, 1 => 2, 2 => 3];
unset($d[2]);
$d[2] = 9;
echo array_is_list($d) ? "trailing_restore\n" : "bad_trailing\n";
--EXPECT--
restore_list
restore_count
nonzero_start
middle_not_list
middle_count
trailing_restore
