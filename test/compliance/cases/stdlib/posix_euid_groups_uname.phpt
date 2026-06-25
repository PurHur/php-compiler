--TEST--
posix_geteuid()/posix_getegid()/posix_getgroups()/posix_uname() VM libc FFI (#6123)
--SKIPIF--
<?php if (!function_exists('posix_geteuid')) die('skip no host posix'); ?>
--FILE--
<?php
echo (int) function_exists('posix_geteuid'), "\n";
echo (int) function_exists('posix_getgroups'), "\n";
echo (int) function_exists('posix_uname'), "\n";
$euid = posix_geteuid();
echo ($euid >= 0 ? 'euid-ok' : 'euid-bad'), "\n";
$egid = posix_getegid();
echo ($egid >= 0 ? 'egid-ok' : 'egid-bad'), "\n";
echo count(posix_getgroups()) >= 0 ? 'groups-ok' : 'groups-fail', "\n";
$uname = posix_uname();
echo isset($uname['sysname']) && isset($uname['nodename']) && isset($uname['release'])
    && isset($uname['version']) && isset($uname['machine']) && isset($uname['domainname'])
    ? 'uname-keys'
    : 'uname-fail', "\n";
?>
--EXPECT--
1
1
1
euid-ok
egid-ok
groups-ok
uname-keys
