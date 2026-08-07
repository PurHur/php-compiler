--TEST--
stdlib substr() empty/null subject + oversize length: no String is truncated on PROFILE=8.4 JIT (#22489, #28556)
--ENV--
PHP_COMPILER_PROFILE=8.4
--JIT--
--FILE--
<?php
error_reporting(E_ALL);
$warnings = [];
set_error_handler(static function (int $no, string $msg) use (&$warnings): bool {
    $warnings[] = $msg;
    return true;
});
$r1 = substr(null, 0, 1);
$r2 = substr('', 0, 1);
$r3 = substr('ab', 5, 1);
$r4 = substr('ab', 2, 1);
$r5 = substr('hello', 0, 50);
$r6 = substr('abc', 1, PHP_INT_MAX);
$truncated = 0;
$dep = 0;
foreach ($warnings as $msg) {
    if (str_contains($msg, 'String is truncated')) {
        $truncated++;
    }
    if (str_contains($msg, 'Passing null')) {
        $dep++;
    }
}
echo 'r1=', var_export($r1, true), "\n";
echo 'r2=', var_export($r2, true), "\n";
echo 'r3=', var_export($r3, true), "\n";
echo 'r4=', var_export($r4, true), "\n";
echo 'r5=', var_export($r5, true), "\n";
echo 'r6=', var_export($r6, true), "\n";
echo "truncated_warning=$truncated\n";
echo "dep_warning=$dep\n";
?>
--EXPECT--
r1=''
r2=''
r3=''
r4=''
r5='hello'
r6='bc'
truncated_warning=0
dep_warning=1
