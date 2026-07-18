--TEST--
posix POSIX_S_IF* mode constants (php-src-strict, issue #20517)
--SKIPIF--
<?php if (!extension_loaded('posix') && !function_exists('posix_mknod')) die('skip no posix'); ?>
--FILE--
<?php
declare(strict_types=1);
foreach (['POSIX_S_IFIFO', 'POSIX_S_IFCHR', 'POSIX_S_IFBLK', 'POSIX_S_IFREG', 'POSIX_S_IFSOCK'] as $c) {
    echo $c, ' ', defined($c) ? 'Y=' . constant($c) : 'N', "\n";
}
foreach (['S_IFIFO', 'S_IFCHR', 'S_IFDIR', 'S_IFBLK', 'S_IFREG', 'S_IFLNK', 'S_IFSOCK', 'POSIX_S_IFDIR', 'POSIX_S_IFLNK'] as $c) {
    echo $c, ' ', defined($c) ? 'Y' : 'N', "\n";
}
// Zend values (sys/stat.h octal on Linux)
echo 'eq ', (int) (POSIX_S_IFIFO === 0010000), (int) (POSIX_S_IFCHR === 0020000),
    (int) (POSIX_S_IFBLK === 0060000), (int) (POSIX_S_IFREG === 0100000),
    (int) (POSIX_S_IFSOCK === 0140000), "\n";
?>
--EXPECT--
POSIX_S_IFIFO Y=4096
POSIX_S_IFCHR Y=8192
POSIX_S_IFBLK Y=24576
POSIX_S_IFREG Y=32768
POSIX_S_IFSOCK Y=49152
S_IFIFO N
S_IFCHR N
S_IFDIR N
S_IFBLK N
S_IFREG N
S_IFLNK N
S_IFSOCK N
POSIX_S_IFDIR N
POSIX_S_IFLNK N
eq 11111
