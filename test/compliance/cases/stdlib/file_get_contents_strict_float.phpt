--TEST--
stdlib file_get_contents() float offset under strict call site (#13851, ext/standard/file.c)
--FILE--
<?php
declare(strict_types=1);
$path = sys_get_temp_dir().'/phpc_fgc_strict_'.getmypid().'.txt';
file_put_contents($path, 'abcdef');
try {
    file_get_contents($path, false, null, 1.9, 2);
    echo "uncaught\n";
} catch (TypeError $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
@unlink($path);
--EXPECT--
TypeError: file_get_contents(): Argument #4 ($offset) must be of type int, float given
