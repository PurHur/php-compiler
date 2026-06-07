--TEST--
posix_access() readable probe (VM, issue #7376)
--SKIPIF--
<?php if (!function_exists('posix_access')) die('skip no host posix'); ?>
--FILE--
<?php
declare(strict_types=1);
echo (int) function_exists('posix_access'), "\n";
echo (int) posix_access('/tmp', POSIX_R_OK | POSIX_X_OK), "\n";
echo (int) posix_access('/no/such/path-' . getmypid(), POSIX_F_OK), "\n";
?>
--EXPECT--
1
1
0
