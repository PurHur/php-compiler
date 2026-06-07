--TEST--
posix_setegid() restore effective gid (VM, issue #7376)
--SKIPIF--
<?php if (!function_exists('posix_setegid') || !function_exists('posix_getegid')) die('skip no host posix'); ?>
--FILE--
<?php
declare(strict_types=1);
echo (int) function_exists('posix_setegid'), "\n";
var_export(posix_setegid(posix_getegid()));
echo "\n";
?>
--EXPECT--
1
true
