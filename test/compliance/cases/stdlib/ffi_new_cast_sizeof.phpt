--TEST--
Stdlib: FFI::new/cast/typeof/sizeof/addr/isNull/free (#22369)
--SKIPIF--
<?php
if (!extension_loaded('ffi') || !class_exists('FFI', false)) {
    echo "skip host ext/ffi not available\n";
}
?>
--FILE--
<?php
foreach (['new', 'cast', 'typeof', 'sizeof', 'addr', 'isNull', 'free'] as $m) {
    echo $m, '=', method_exists('FFI', $m) ? '1' : '0', "\n";
}
$i = FFI::new('int');
echo is_object($i) ? "new=object\n" : "new=fail\n";
echo 'class=', $i instanceof FFI\CData ? 'CData' : get_class($i), "\n";
$i->cdata = 42;
echo 'cdata=', $i->cdata, "\n";
echo 'sizeof=', FFI::sizeof($i), "\n";
$t = FFI::typeof($i);
echo 'typeof=', $t instanceof FFI\CType ? 'CType' : get_class($t), "\n";
echo 'sizeof_type=', FFI::sizeof($t), "\n";
$p = FFI::addr($i);
echo 'addr=', $p instanceof FFI\CData ? 'CData' : get_class($p), "\n";
$c = FFI::cast('int*', $p);
echo 'cast=', $c instanceof FFI\CData ? 'CData' : get_class($c), "\n";
$n = FFI::new('void*');
echo 'isnull=', FFI::isNull($n) ? '1' : '0', "\n";
$owned = FFI::new('int', false, true);
FFI::free($owned);
echo "free=ok\n";
?>
--EXPECT--
new=1
cast=1
typeof=1
sizeof=1
addr=1
isNull=1
free=1
new=object
class=CData
cdata=42
sizeof=4
typeof=CType
sizeof_type=4
addr=CData
cast=CData
isnull=1
free=ok
