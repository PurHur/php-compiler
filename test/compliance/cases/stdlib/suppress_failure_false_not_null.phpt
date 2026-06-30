--TEST--
@ suppression keeps false failure returns when nested arg calls run (issue #9332, #14043)
--FILE--
<?php
$missing = '/no/such/phpc-'.getmypid();
echo @copy($missing, sys_get_temp_dir().'/dst.txt') === false ? "copy\n" : "copy-bad\n";
echo @touch('/nonexistent_parent_'.getmypid().'/f.txt') === false ? "touch\n" : "touch-bad\n";
echo @stat($missing) === false ? "stat\n" : "stat-bad\n";
echo @fopen($missing, 'r') === false ? "fopen\n" : "fopen-bad\n";
--EXPECT--
copy
touch
stat
fopen
