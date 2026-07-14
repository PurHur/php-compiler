--TEST--
stdlib filestat path builtins — null TypeError on 8.4 forward profile JIT (#18817, ext/standard/filestat.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--JIT--
--FILE--
<?php
foreach (['touch', 'unlink', 'rename', 'mkdir', 'filesize'] as $fn) {
    try {
        if ('rename' === $fn) {
            rename(null, 'b');
        } elseif ('mkdir' === $fn) {
            @mkdir(null);
        } elseif ('touch' === $fn) {
            touch(null);
        } elseif ('filesize' === $fn) {
            filesize(null);
        } else {
            @unlink(null);
        }
        echo "$fn: uncaught\n";
    } catch (TypeError $e) {
        echo $fn.': '.$e->getMessage()."\n";
    }
}
--EXPECT--
touch: touch(): Argument #1 ($filename) must be of type string, null given
unlink: unlink(): Argument #1 ($filename) must be of type string, null given
rename: rename(): Argument #1 ($from) must be of type string, null given
mkdir: mkdir(): Argument #1 ($directory) must be of type string, null given
filesize: filesize(): Argument #1 ($filename) must be of type string, null given
