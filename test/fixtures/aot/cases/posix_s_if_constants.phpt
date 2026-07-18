--TEST--
AOT: POSIX_S_IF* mode constants (#20517)
--FILE--
<?php
echo defined('POSIX_S_IFIFO') ? 'Y' : 'N';
echo defined('POSIX_S_IFCHR') ? 'Y' : 'N';
echo defined('POSIX_S_IFBLK') ? 'Y' : 'N';
echo defined('POSIX_S_IFREG') ? 'Y' : 'N';
echo defined('POSIX_S_IFSOCK') ? 'Y' : 'N';
echo defined('S_IFIFO') ? 'Y' : 'N', "\n";
echo POSIX_S_IFIFO | 0644, "\n";
?>
--EXPECT--
YYYYYN
4516
