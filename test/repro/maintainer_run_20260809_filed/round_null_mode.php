<?php
/**
 * #29384 — round(..., null) $mode: Zend DEP+coerce then ValueError (not TypeError).
 * php-src: ext/standard/math.c / basic_functions.stub.php — RoundingMode|int $mode
 *
 * Named handler for VM/JIT DEP capture. AOT may skip set_error_handler (see aot fixture).
 */
error_reporting(E_ALL);

function round_null_mode_29384_handler($no, $str)
{
    echo ($no === E_DEPRECATED ? 'DEP' : 'W'), ': ', $str, "\n";

    return true;
}

set_error_handler('round_null_mode_29384_handler');
try {
    var_export(round(1.5, 0, null));
    echo "\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
try {
    var_export(round(1.5, 0, 99));
    echo "\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
