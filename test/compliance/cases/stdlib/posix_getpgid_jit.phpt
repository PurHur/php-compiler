--TEST--
posix_getsid()/posix_getpgid()/posix_setpgid() JIT/AOT — session and PGID (#6505)
--SKIPIF--
<?php if (!function_exists('posix_getpgid')) die('skip no host posix'); ?>
--FILE--
<?php
echo (int) function_exists('posix_getsid'), "\n";
echo (int) function_exists('posix_getpgid'), "\n";
echo (int) function_exists('posix_setpgid'), "\n";
$pid = posix_getpid();
$pgid = posix_getpgid($pid);
$sid = posix_getsid($pid);
echo ($pgid !== false && $pgid > 0 ? 'pgid-ok' : 'pgid-bad'), "\n";
echo ($sid !== false && $sid > 0 ? 'sid-ok' : 'sid-bad'), "\n";
echo ($pgid === posix_getpgid($pid) ? 'pgid-stable' : 'pgid-unstable'), "\n";
?>
--EXPECT--
1
1
1
pgid-ok
sid-ok
pgid-stable
