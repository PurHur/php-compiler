--TEST--
SPL ArrayObject::__debugInfo() — var_dump/print_r show private storage (#19764, ext/spl/spl_array.c)
--FILE--
<?php
$o = new ArrayObject(['a' => 1]);
ob_start();
var_dump($o);
$vd = ob_get_clean();
echo (str_contains($vd, 'storage') && str_contains($vd, '["a"]') && str_contains($vd, ':private'))
    ? "var_dump_ok\n" : "var_dump_fail\n";

$o->dyn = 5;
ob_start();
var_dump($o);
$vd2 = ob_get_clean();
echo (str_contains($vd2, '["dyn"]') && str_contains($vd2, 'storage'))
    ? "dyn_ok\n" : "dyn_fail\n";

ob_start();
print_r($o);
$pr = ob_get_clean();
echo (str_contains($pr, 'storage:ArrayObject:private') && str_contains($pr, '[a] => 1'))
    ? "print_r_ok\n" : "print_r_fail\n";

$info = $o->__debugInfo();
$keys = array_keys($info);
$hasStorage = false;
foreach ($keys as $k) {
    if (is_string($k) && str_contains($k, 'storage')) {
        $hasStorage = true;
        break;
    }
}
echo ($hasStorage && isset($info["\0ArrayObject\0storage"]['a']) && $info["\0ArrayObject\0storage"]['a'] === 1
    && isset($info['dyn']) && $info['dyn'] === 5)
    ? "debuginfo_ok\n" : "debuginfo_fail\n";
?>
--EXPECT--
var_dump_ok
dyn_ok
print_r_ok
debuginfo_ok
