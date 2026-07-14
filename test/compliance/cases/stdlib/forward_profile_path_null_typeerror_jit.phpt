--TEST--
stdlib Z_PARAM_PATH builtins — null TypeError on 8.4 forward profile JIT (#18817, ext/standard/filestat.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--JIT--
--FILE--
<?php
foreach ([
    'touch' => static fn () => touch(null),
    'unlink' => static fn () => @unlink(null),
    'rename' => static fn () => rename(null, 'b'),
    'mkdir' => static fn () => @mkdir(null),
    'filesize' => static fn () => filesize(null),
] as $label => $factory) {
    try {
        $factory();
        echo "$label: uncaught\n";
    } catch (TypeError $e) {
        echo $label.': '.$e->getMessage()."\n";
    }
}
--EXPECT--
touch: touch(): Argument #1 ($filename) must be of type string, null given
unlink: unlink(): Argument #1 ($filename) must be of type string, null given
rename: rename(): Argument #1 ($from) must be of type string, null given
mkdir: mkdir(): Argument #1 ($directory) must be of type string, null given
filesize: filesize(): Argument #1 ($filename) must be of type string, null given
