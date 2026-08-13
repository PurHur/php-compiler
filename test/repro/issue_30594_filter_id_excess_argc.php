<?php
/**
 * filter_id() excess argc → ArgumentCountError (#30594).
 * php-src: ext/filter/filter.c
 */
try {
    $r = filter_id('int', 'x');
    echo 'NO_THROW ', var_export($r, true), "\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
try {
    filter_id();
    echo "NO_THROW_ZERO\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
$id = filter_id('int');
echo 'filter_id_ok=', var_export($id, true), "\n";
