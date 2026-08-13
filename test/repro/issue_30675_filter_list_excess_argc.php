<?php
/**
 * filter_list() excess argc → ArgumentCountError (#30675).
 * php-src: ext/filter/filter.c
 */
try {
    $r = filter_list('x');
    echo 'NO_THROW ', var_export($r, true), "\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
$ok = filter_list();
echo is_array($ok) && in_array('int', $ok, true) ? "filter_list_ok\n" : "filter_list_fail\n";
