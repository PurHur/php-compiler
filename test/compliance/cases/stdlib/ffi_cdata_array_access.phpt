--TEST--
Stdlib: FFI\CData ArrayAccess for C arrays (#22761)
--SKIPIF--
<?php
if (!extension_loaded('ffi') || !class_exists('FFI', false)) {
    echo "skip host ext/ffi not available\n";
}
?>
--FILE--
<?php
$a = FFI::new('int[3]');
$a[0] = 1;
$a[1] = 2;
$a[2] = 3;
echo $a[0], ' ', $a[1], ' ', $a[2], "\n";
$a[1] = 99;
echo 'mid=', $a[1], "\n";
?>
--EXPECT--
1 2 3
mid=99
