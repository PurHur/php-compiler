<?php

/**
 * #21246 — unpack($string=null) under PHP_COMPILER_PROFILE=8.4.
 *
 * Zend: E_DEPRECATED + Warning (insufficient input for 'C') → false (no TypeError).
 * $format null remains Z_PARAM_STR TypeError (#20241).
 */
error_reporting(E_ALL);
$dep = 0;
$warn = 0;
set_error_handler(static function (int $no, string $msg) use (&$dep, &$warn): bool {
    if (E_DEPRECATED === $no) {
        ++$dep;
        echo "DEP\n";

        return true;
    }
    if (E_WARNING === $no) {
        ++$warn;
        echo "WARN\n";

        return true;
    }

    return false;
});
try {
    $r = unpack('C', null);
    echo var_export($r, true), "\n";
} catch (TypeError $e) {
    echo 'TypeError: ', $e->getMessage(), "\n";
}
restore_error_handler();
echo 'dep=', $dep, ' warn=', $warn, "\n";
try {
    unpack(null, 'x');
    echo "fmt COERCED\n";
} catch (TypeError $e) {
    echo "fmt TypeError\n";
}
