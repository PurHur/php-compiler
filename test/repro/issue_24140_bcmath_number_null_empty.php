<?php
/**
 * Repro #24140 — BcMath\Number(null|"") → soft-null deprecate + value "0" (php-src-strict).
 * Run: PHP_COMPILER_PROFILE=8.4 php bin/vm.php test/repro/issue_24140_bcmath_number_null_empty.php
 */
use BcMath\Number;

error_reporting(E_ALL);
set_error_handler(static function (int $no, string $msg): bool {
    if (E_DEPRECATED === $no) {
        echo 'DEP:', $msg, "\n";

        return true;
    }

    return false;
});

$n = new Number(null);
echo 'null value=', var_export($n->value, true), ' scale=', $n->scale, "\n";
$n = new Number('');
echo 'empty value=', var_export($n->value, true), ' scale=', $n->scale, "\n";
