<?php
/**
 * Repro #26378 — PHP 8.3+ E_WARNING when ++/-- on bool or -- on null has no effect.
 *
 * php-src: Zend/zend_operators.c increment_function / decrement_function
 *
 *   PHP_COMPILER_PROFILE=8.4 php bin/vm.php test/repro/issue_26378_incdec_bool_null_warn.php
 *   PHP_COMPILER_PROFILE=8.4 php bin/jit.php test/repro/issue_26378_incdec_bool_null_warn.php
 */
error_reporting(E_ALL);
set_error_handler(static function (int $n, string $s): bool {
    echo 'W:', $s, "\n";

    return true;
});

$b = null;
$b--;
var_export($b);
echo "\n";

$f = false;
$f++;
var_export($f);
echo "\n";

$t = true;
$t--;
var_export($t);
echo "\n";

$a = null;
$a++;
var_export($a);
echo "\n";
