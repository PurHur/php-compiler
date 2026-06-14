--TEST--
AOT: posix_getpgid()/posix_getsid() — process group and session IDs (#6505)
--SKIPIF--
<?php if (!function_exists('posix_getpgid')) die('skip no host posix'); ?>
--FILE--
<?php
echo posix_getpgid(posix_getpid()) > 0 ? 'pgid-ok' : 'pgid-bad', "\n";
echo posix_getsid(posix_getpid()) > 0 ? 'sid-ok' : 'sid-bad', "\n";
echo posix_getpgid(posix_getpid()) === posix_getpgid(posix_getpid()) ? 'pgid-stable' : 'pgid-unstable', "\n";
?>
--EXPECT--
pgid-ok
sid-ok
pgid-stable
