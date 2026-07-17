--TEST--
SPL ArrayIterator::__debugInfo() — var_dump/print_r show private storage (#19782, ext/spl/spl_array.c)
--FILE--
<?php
$i = new ArrayIterator(['x' => 9]);
ob_start();
var_dump($i);
$vd = ob_get_clean();
echo (str_contains($vd, 'storage') && str_contains($vd, '["x"]') && str_contains($vd, ':private'))
    ? "var_dump_ok\n" : "var_dump_fail\n";

ob_start();
print_r($i);
$pr = ob_get_clean();
echo (str_contains($pr, 'storage:ArrayIterator:private') && str_contains($pr, '[x] => 9'))
    ? "print_r_ok\n" : "print_r_fail\n";

$info = $i->__debugInfo();
echo (isset($info["\0ArrayIterator\0storage"]['x']) && $info["\0ArrayIterator\0storage"]['x'] === 9)
    ? "debuginfo_ok\n" : "debuginfo_fail\n";
?>
--EXPECT--
var_dump_ok
print_r_ok
debuginfo_ok
