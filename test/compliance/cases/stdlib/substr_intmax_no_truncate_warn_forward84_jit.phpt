--TEST--
stdlib substr() PHP_INT_MAX length: no String is truncated on PROFILE=8.4 JIT (#28556)
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
$out = substr('abc', 1, PHP_INT_MAX);
$truncated = 0;
foreach ($warnings as $msg) {
    if (str_contains($msg, 'String is truncated')) {
        $truncated++;
    }
}
echo 'out=', var_export($out, true), "\n";
echo "truncated_warning=$truncated\n";
?>
--EXPECT--
out='bc'
truncated_warning=0
