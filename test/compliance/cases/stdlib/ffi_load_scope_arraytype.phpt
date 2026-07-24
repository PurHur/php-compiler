--TEST--
Stdlib: FFI::load/scope/arrayType (#22759)
--SKIPIF--
<?php
if (!extension_loaded('ffi') || !class_exists('FFI', false)) {
    echo "skip host ext/ffi not available\n";
}
?>
--FILE--
<?php
foreach (['load', 'scope', 'arrayType'] as $m) {
    echo $m, '=', method_exists('FFI', $m) ? '1' : '0', "\n";
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
echo 'abs=', $ffi->abs(-5), "\n";
@unlink($dir.'/t.h');
@rmdir($dir);
$t = FFI::arrayType(FFI::type('int'), [3]);
echo 'arrayType=', $t instanceof FFI\CType ? 'CType' : get_class($t), "\n";
$a = FFI::new($t);
echo 'sizeof=', FFI::sizeof($a), "\n";
$a[0] = 7;
echo 'a0=', $a[0], "\n";
?>
--EXPECT--
load=1
scope=1
arrayType=1
scope=Exception
load=object
abs=5
arrayType=CType
sizeof=12
a0=7
