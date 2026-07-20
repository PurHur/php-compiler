<?php
// #21491 — idate/mktime/gmmktime null soft-null under PROFILE=8.4 (Zend DEP+coerce)
error_reporting(E_ALL);
set_error_handler(static function (int $n, string $m): bool {
    if (E_DEPRECATED === $n) {
        echo "DEP\n";
        return true;
    }
    if (E_WARNING === $n) {
        echo "WARN\n";
        return true;
    }
    return false;
});
foreach ([
    'idate' => static fn () => idate(null),
    'mktime' => static fn () => mktime(null),
    'gmmktime' => static fn () => gmmktime(null),
] as $name => $fn) {
    try {
        $r = $fn();
        if ('idate' === $name) {
            echo $name, ' OK ', var_export($r, true), "\n";
        } else {
            echo $name, ' OK ', (is_int($r) ? 'int' : gettype($r)), "\n";
        }
    } catch (Throwable $e) {
        echo $name, ' ', get_class($e), "\n";
    }
}
echo "ALL_OK\n";
