--TEST--
language: float % int emits E_DEPRECATED on precision loss (#23747, zend_operators.c mod_function)
--FILE--
<?php
error_reporting(E_ALL);
$seen = [];
set_error_handler(static function (int $no, string $str) use (&$seen): bool {
    $seen[] = [$no, $str];
    return true;
});

$seen = [];
echo 'lossy=', var_export(5.5 % 2, true), "\n";
echo 'lossy_depr=', (isset($seen[0]) && E_DEPRECATED === $seen[0][0]) ? '1' : '0', "\n";

$seen = [];
echo 'int_only=', var_export(5 % 2, true), "\n";
echo 'int_silent=', empty($seen) ? '1' : '0', "\n";

$seen = [];
echo 'integral_float=', var_export(5.0 % 2, true), "\n";
echo 'integral_silent=', empty($seen) ? '1' : '0', "\n";

$seen = [];
echo 'both_lossy=', var_export(5.5 % 2.5, true), "\n";
echo 'both_count=', count($seen), "\n";
--EXPECT--
lossy=1
lossy_depr=1
int_only=1
int_silent=1
integral_float=1
integral_silent=1
both_lossy=1
both_count=2
