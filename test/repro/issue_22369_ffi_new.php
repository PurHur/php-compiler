<?php
// Repro for #22369 — FFI::new/cast/typeof/sizeof/addr/isNull/free after cdef.
foreach (['cdef', 'new', 'cast', 'typeof', 'sizeof', 'addr', 'isNull', 'free'] as $m) {
    echo $m, '=', method_exists('FFI', $m) ? '1' : '0', PHP_EOL;
}
$i = FFI::new('int');
$i->cdata = 7;
echo 'sizeof=', FFI::sizeof($i), PHP_EOL;
echo 'cdata=', $i->cdata, PHP_EOL;
$t = FFI::typeof($i);
echo 'typeof=', $t instanceof FFI\CType ? 'CType' : get_class($t), PHP_EOL;
$p = FFI::addr($i);
$c = FFI::cast('int*', $p);
echo 'cast=', $c instanceof FFI\CData ? 'CData' : get_class($c), PHP_EOL;
$n = FFI::new('void*');
echo 'isnull=', FFI::isNull($n) ? '1' : '0', PHP_EOL;
$owned = FFI::new('int', false, true);
FFI::free($owned);
echo "free_ok\n";
