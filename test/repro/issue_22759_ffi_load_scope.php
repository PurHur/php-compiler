<?php
// Repro for #22759 — FFI::load / scope / arrayType after #22369.
foreach (['load', 'scope', 'arrayType'] as $m) {
    echo $m, '=', method_exists('FFI', $m) ? '1' : '0', PHP_EOL;
}
try {
    FFI::scope('nosuch_phpc_scope');
    echo "scope=ok\n";
} catch (FFI\Exception $e) {
    echo "scope=Exception\n";
}
$dir = sys_get_temp_dir().'/phpc_ffi_'.getmypid();
@mkdir($dir);
file_put_contents($dir.'/t.h', "#define FFI_LIB \"\"\nint abs(int);\n");
$ffi = FFI::load($dir.'/t.h');
echo is_object($ffi) ? "load=object\n" : "load=fail\n";
echo 'abs=', $ffi->abs(-5), PHP_EOL;
@unlink($dir.'/t.h');
@rmdir($dir);
$t = FFI::arrayType(FFI::type('int'), [3]);
echo 'arrayType=', $t instanceof FFI\CType ? 'CType' : get_class($t), PHP_EOL;
$a = FFI::new($t);
echo 'sizeof=', FFI::sizeof($a), PHP_EOL;
$a[0] = 7;
echo 'a0=', $a[0], PHP_EOL;
