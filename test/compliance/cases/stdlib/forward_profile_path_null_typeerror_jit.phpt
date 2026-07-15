--TEST--
stdlib Z_PARAM_PATH filestat builtins — null coerces on 8.4 forward profile JIT (#19146 supersedes #18817, ext/standard/filestat.c)
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
    } catch (ValueError $e) {
        echo $label.': ValueError: '.$e->getMessage()."\n";
    }
}
--EXPECT--
touch: uncaught
unlink: uncaught
rename: uncaught
mkdir: uncaught
filesize: uncaught
