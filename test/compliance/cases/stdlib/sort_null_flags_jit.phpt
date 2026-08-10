--TEST--
stdlib sort()/rsort()/asort()/arsort()/ksort()/krsort() null $flags — Z_PARAM_LONG soft-null DEP (JIT) (#29385, ext/standard/array.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
error_reporting(E_ALL);
set_error_handler(static function (int $errno, string $errstr): bool {
    echo "ERR[$errno]: $errstr\n";

    return true;
});
$a = [3, 1, 2];
echo var_export(sort($a, null), true), "\n";
echo implode(',', $a), "\n";
$b = [3, 1, 2];
echo var_export(rsort($b, null), true), "\n";
echo implode(',', $b), "\n";
$c = [3, 1, 2];
echo var_export(asort($c, null), true), "\n";
echo implode(',', $c), "\n";
$d = [3, 1, 2];
echo var_export(arsort($d, null), true), "\n";
echo implode(',', $d), "\n";
$e = [3, 1, 2];
echo var_export(ksort($e, null), true), "\n";
echo implode(',', $e), "\n";
$f = [3, 1, 2];
echo var_export(krsort($f, null), true), "\n";
echo implode(',', $f), "\n";
?>
--EXPECT--
ERR[8192]: sort(): Passing null to parameter #2 ($flags) of type int is deprecated
true
1,2,3
ERR[8192]: rsort(): Passing null to parameter #2 ($flags) of type int is deprecated
true
3,2,1
ERR[8192]: asort(): Passing null to parameter #2 ($flags) of type int is deprecated
true
1,2,3
ERR[8192]: arsort(): Passing null to parameter #2 ($flags) of type int is deprecated
true
3,2,1
ERR[8192]: ksort(): Passing null to parameter #2 ($flags) of type int is deprecated
true
3,1,2
ERR[8192]: krsort(): Passing null to parameter #2 ($flags) of type int is deprecated
true
2,1,3
