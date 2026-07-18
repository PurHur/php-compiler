--TEST--
stdlib filestat path builtins — null TypeError on 8.4 forward profile JIT (#18817, #20474, ext/standard/filestat.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--JIT--
--FILE--
<?php
foreach (['touch', 'unlink', 'rename', 'mkdir', 'filesize', 'filemtime', 'filetype', 'is_executable'] as $fn) {
    try {
        if ('rename' === $fn) {
            rename(null, 'b');
        } elseif ('mkdir' === $fn) {
            @mkdir(null);
        } elseif ('touch' === $fn) {
            touch(null);
        } elseif ('filesize' === $fn) {
            filesize(null);
        } elseif ('filemtime' === $fn) {
            filemtime(null);
        } elseif ('filetype' === $fn) {
            filetype(null);
        } elseif ('is_executable' === $fn) {
            is_executable(null);
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
filemtime: filemtime(): Argument #1 ($filename) must be of type string, null given
filetype: filetype(): Argument #1 ($filename) must be of type string, null given
is_executable: is_executable(): Argument #1 ($filename) must be of type string, null given
