<?php
/**
 * #29322 — pow(null) must not emit float null DEP under PROFILE=8.4 (Zend operator path).
 * Control: fpow(null) still DEP+coerces.
 */
error_reporting(E_ALL);
set_error_handler(static function (int $errno, string $errstr): bool {
    echo 'DEP: ', $errstr, "\n";

    return true;
});
echo 'pow=', var_export(pow(null, 2), true), "\n";
echo 'pow2=', var_export(pow(2, null), true), "\n";
echo 'fpow=', var_export(fpow(null, 2.0), true), "\n";
