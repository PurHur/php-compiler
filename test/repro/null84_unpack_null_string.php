<?php

/**
 * #21246 / #21478 — unpack() null under PHP_COMPILER_PROFILE=8.4.
 *
 * Zend: $string soft-null DEP+Warning → false; $format soft-null DEP+[] (#21478, reverts #20241).
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
set_error_handler(static function (int $no, string $msg): bool {
    return true;
});
try {
    unpack(null, 'x');
    echo "fmt COERCED\n";
} catch (TypeError $e) {
    echo "fmt TypeError\n";
}
