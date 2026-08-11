--TEST--
Language: unset($false[$k]) Deprecated; unset($null[$k]) silent (#30099, zend_vm_def.h ZEND_UNSET_DIM)
--FILE--
<?php
error_reporting(E_ALL);
$seen = [];
set_error_handler(static function (int $errno, string $msg) use (&$seen): bool {
    if (E_DEPRECATED === $errno) {
        $seen[] = $msg;
    }
    return true;
});

$f = false;
unset($f[0]);
$okFalse = 1 === count($seen)
    && str_contains($seen[0], 'Automatic conversion of false to array is deprecated')
    && false === $f;
echo $okFalse ? "false_ok\n" : "false_bad\n";

$f2 = false;
unset($f2['a']);
$okFalseStr = 2 === count($seen)
    && false === $f2;
echo $okFalseStr ? "false_str_ok\n" : "false_str_bad\n";

$n = null;
unset($n[0]);
$okNull = 2 === count($seen) && null === $n;
echo $okNull ? "null_ok\n" : "null_bad\n";

try {
    $t = true;
    unset($t[0]);
    echo "true_ok\n";
} catch (Error $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}

try {
    $i = 1;
    unset($i[0]);
    echo "int_ok\n";
} catch (Error $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
--EXPECT--
false_ok
false_str_ok
null_ok
Error: Cannot unset offset in a non-array variable
Error: Cannot unset offset in a non-array variable
