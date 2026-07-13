--TEST--
stdlib gc_collect_cycles() return in array — foreach must not fatal (#18612, Zend/zend_gc.c)
--FILE--
<?php
$a = [];
$a['gc'] = gc_collect_cycles();
foreach ($a as $k => $v) {
    echo $k, '=', $v, "\n";
}

$b = [];
$b[gc_collect_cycles()] = 2;
foreach ($b as $k => $v) {
    echo $k, '=', $v, "\n";
}

echo "ok\n";
?>
--EXPECT--
gc=0
0=2
ok
