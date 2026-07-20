--TEST--
stdlib filestat path builtins — null soft-coerces on 8.4 forward profile JIT (#20362 supersedes #20474, ext/standard/filestat.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--JIT--
--FILE--
<?php
error_reporting(E_ALL & ~E_DEPRECATED);
foreach (['touch', 'unlink', 'rename', 'mkdir', 'filesize', 'filemtime', 'filetype', 'is_executable'] as $fn) {
    try {
        if ('rename' === $fn) {
            $r = @rename(null, 'b');
        } elseif ('mkdir' === $fn) {
            $r = @mkdir(null);
        } elseif ('touch' === $fn) {
            $r = @touch(null);
        } elseif ('filesize' === $fn) {
            $r = @filesize(null);
        } elseif ('filemtime' === $fn) {
            $r = @filemtime(null);
        } elseif ('filetype' === $fn) {
            $r = @filetype(null);
        } elseif ('is_executable' === $fn) {
            $r = @is_executable(null);
        } else {
            $r = @unlink(null);
        }
        echo $fn, ':', var_export($r, true), "\n";
    } catch (TypeError $e) {
        echo $fn, ': TypeError: ', $e->getMessage(), "\n";
    } catch (ValueError $e) {
        echo $fn, ': ValueError: ', $e->getMessage(), "\n";
    }
}
--EXPECT--
touch:false
unlink:false
rename:false
mkdir:false
filesize:false
filemtime:false
filetype:false
is_executable:false
