--TEST--
AOT: array_is_list() after unset+restore contiguous keys (#28051)
--FILE--
<?php
$a = [0 => 1, 1 => 2];
unset($a[1]);
$a[1] = 3;
echo array_is_list($a) ? "restore_list\n" : "bad_restore\n";

$b = [1 => 1, 2 => 2];
echo array_is_list($b) ? "bad_nonzero\n" : "nonzero_start\n";
--EXPECT--
restore_list
nonzero_start
