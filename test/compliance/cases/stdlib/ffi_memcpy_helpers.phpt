--TEST--
Stdlib: FFI::memcpy/memcmp/memset/string/alignof/type (#22760)
--SKIPIF--
<?php
if (!extension_loaded('ffi') || !class_exists('FFI', false)) {
    echo "skip host ext/ffi not available\n";
}
?>
--FILE--
<?php
foreach (['memcpy', 'memcmp', 'memset', 'string', 'alignof', 'type'] as $m) {
    echo $m, '=', method_exists('FFI', $m) ? '1' : '0', "\n";
}
$a = FFI::new('char[16]');
FFI::memset($a, 0, 16);
FFI::memcpy($a, 'hi', 2);
echo 'string=', FFI::string($a, 2), "\n";
echo 'memcmp=', FFI::memcmp($a, 'hi', 2), "\n";
echo 'alignof=', FFI::alignof($a), "\n";
$t = FFI::type('int');
echo 'type=', $t instanceof FFI\CType ? 'CType' : get_class($t), "\n";
echo 'sizeof_type=', FFI::sizeof($t), "\n";
?>
--EXPECT--
memcpy=1
memcmp=1
memset=1
string=1
alignof=1
type=1
string=hi
memcmp=0
alignof=1
type=CType
sizeof_type=4
