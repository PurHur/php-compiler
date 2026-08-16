--TEST--
stdlib file_get_contents(null $use_include_path) TypeError under strict_types (#31338, ext/standard/file.c)
--FILE--
<?php
declare(strict_types=1);
$path = sys_get_temp_dir().'/phpc_fgc_null_uip_'.getmypid().'.txt';
file_put_contents($path, 'hello');
try {
    file_get_contents($path, null);
    echo "fail\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
// omit-arg still defaults to false (read without include_path)
$r = file_get_contents($path);
echo is_string($r) && $r === 'hello' ? "omit_ok\n" : "omit_fail\n";
@unlink($path);
--EXPECT--
file_get_contents(): Argument #2 ($use_include_path) must be of type bool, null given
omit_ok
