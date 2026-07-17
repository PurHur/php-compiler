--TEST--
SPL object storage __debugInfo() — var_dump shows private storage (#19826, ext/spl/spl_observer.c)
--FILE--
<?php
$s = new SplObjectStorage();
$o = new stdClass();
$s[$o] = 'v';
ob_start();
var_dump($s);
$vd = ob_get_clean();
echo (str_contains($vd, 'object(SplObjectStorage)') && str_contains($vd, 'storage')
    && str_contains($vd, ':private') && str_contains($vd, '["obj"]') && str_contains($vd, '["inf"]')
    && str_contains($vd, 'string(1) "v"') && str_contains($vd, 'object(stdClass)'))
    ? "var_dump_ok\n" : "var_dump_fail\n";

ob_start();
print_r($s);
$pr = ob_get_clean();
echo (str_contains($pr, 'storage:SplObjectStorage:private') && str_contains($pr, '[obj]')
    && str_contains($pr, '[inf]') && str_contains($pr, 'v'))
    ? "print_r_ok\n" : "print_r_fail\n";

$info = $s->__debugInfo();
echo (isset($info["\0SplObjectStorage\0storage"][0]['inf'])
    && $info["\0SplObjectStorage\0storage"][0]['inf'] === 'v')
    ? "debuginfo_ok\n" : "debuginfo_fail\n";
?>
--EXPECT--
var_dump_ok
print_r_ok
debuginfo_ok
