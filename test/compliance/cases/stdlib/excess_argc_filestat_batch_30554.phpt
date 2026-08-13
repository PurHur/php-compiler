--TEST--
umask/chown/chgrp/clearstatcache/stat/lstat/file*/fnmatch excess argc → ArgumentCountError (#30554)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
$cases = [
    'umask(0, "x")',
    'chown("/tmp", "root", "x")',
    'chgrp("/tmp", "root", "x")',
    'clearstatcache(true, "/tmp", "x")',
    'stat("/tmp", "x")',
    'lstat("/tmp", "x")',
    'fileinode("/tmp", "x")',
    'fileowner("/tmp", "x")',
    'filegroup("/tmp", "x")',
    'fileperms("/tmp", "x")',
    'fnmatch("*", "a", 0, "x")',
];
foreach ($cases as $code) {
    try {
        eval($code.';');
        echo "$code => NO_THROW\n";
    } catch (ArgumentCountError $e) {
        echo $e->getMessage(), "\n";
    } catch (Throwable $e) {
        echo get_class($e), ':', $e->getMessage(), "\n";
    }
}
?>
--EXPECT--
umask() expects at most 1 argument, 2 given
chown() expects exactly 2 arguments, 3 given
chgrp() expects exactly 2 arguments, 3 given
clearstatcache() expects at most 2 arguments, 3 given
stat() expects exactly 1 argument, 2 given
lstat() expects exactly 1 argument, 2 given
fileinode() expects exactly 1 argument, 2 given
fileowner() expects exactly 1 argument, 2 given
filegroup() expects exactly 1 argument, 2 given
fileperms() expects exactly 1 argument, 2 given
fnmatch() expects at most 3 arguments, 4 given
