--TEST--
SPL SplFixedArray::__debugInfo() — var_dump/print_r show indexed elements (#19783, ext/spl/spl_fixedarray.c)
--FILE--
<?php
$a = new SplFixedArray(2);
$a[0] = 1;
ob_start();
var_dump($a);
$vd = ob_get_clean();
echo (str_contains($vd, 'object(SplFixedArray)') && str_contains($vd, "[0]=>\n")
    && str_contains($vd, 'int(1)') && str_contains($vd, "[1]=>\n") && str_contains($vd, 'NULL')
    && !str_contains($vd, '["0"]') && preg_match('/\(2\)/', $vd))
    ? "var_dump_ok\n" : "var_dump_fail\n";

ob_start();
print_r($a);
$pr = ob_get_clean();
echo (str_contains($pr, '[0] => 1') && str_contains($pr, '[1] =>') && !str_contains($pr, '["0"]'))
    ? "print_r_ok\n" : "print_r_fail\n";

$info = $a->__debugInfo();
echo (isset($info[0]) && $info[0] === 1 && array_key_exists(1, $info) && $info[1] === null && count($info) === 2)
    ? "debuginfo_ok\n" : "debuginfo_fail\n";

$empty = new SplFixedArray(0);
ob_start();
var_dump($empty);
$vd0 = ob_get_clean();
echo (str_contains($vd0, 'object(SplFixedArray)') && preg_match('/\(0\)/', $vd0))
    ? "empty_ok\n" : "empty_fail\n";
?>
--EXPECT--
var_dump_ok
print_r_ok
debuginfo_ok
empty_ok
