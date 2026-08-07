<?php
/**
 * #28566 — bcround() $mode requires RoundingMode under PROFILE≥8.4 (not int).
 * php-src: ext/bcmath/bcmath.stub.php
 *
 * Run:
 *   PHP_COMPILER_PROFILE=8.4 php bin/vm.php test/repro/issue_28566_bcround_mode_roundingmode_only.php
 *   PHP_COMPILER_PROFILE=8.4 php bin/jit.php test/repro/issue_28566_bcround_mode_roundingmode_only.php
 *   PHP_COMPILER_PROFILE=8.4 ./phpc build -o /tmp/bcround28566 test/repro/issue_28566_bcround_mode_roundingmode_only.php && /tmp/bcround28566
 */
error_reporting(E_ALL);

try {
    var_export(bcround('1.25', 1, PHP_ROUND_HALF_UP));
    echo " INT_OK\n";
} catch (TypeError $e) {
    echo 'INT:', $e->getMessage(), "\n";
}

try {
    var_export(bcround('1.25', 1, RoundingMode::HalfEven));
    echo " ENUM_OK\n";
} catch (TypeError $e) {
    echo 'ENUM:', $e->getMessage(), "\n";
}

try {
    var_export(bcround('1.25', 1, null));
    echo " NULL_OK\n";
} catch (TypeError $e) {
    echo 'NULL:', $e->getMessage(), "\n";
}

echo 'def=', var_export(bcround('1.25', 1), true), "\n";
