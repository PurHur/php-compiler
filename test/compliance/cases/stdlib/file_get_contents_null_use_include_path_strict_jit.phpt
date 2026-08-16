--TEST--
stdlib file_get_contents(null $use_include_path) TypeError under strict_types JIT (#31338)
--FILE--
<?php
declare(strict_types=1);
$path = sys_get_temp_dir().'/phpc_fgc_null_uip_jit_'.getmypid().'.txt';
file_put_contents($path, 'hello');
try {
    file_get_contents($path, null);
    echo "fail\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
echo file_get_contents($path) === 'hello' ? "ok\n" : "fail\n";
@unlink($path);
--EXPECT--
file_get_contents(): Argument #2 ($use_include_path) must be of type bool, null given
ok
