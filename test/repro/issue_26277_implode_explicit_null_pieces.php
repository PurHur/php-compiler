<?php

/**
 * #26277 — PROFILE≥8.4 frameless implode(2) rejects array-first + explicit null;
 * join keeps zif_implode array-first (php-src string.c / string_arginfo.h).
 */
error_reporting(E_ALL & ~E_DEPRECATED);

foreach ([
    'implode([1,2], null)',
    'implode([], null)',
    'implode([1,2])',
    'join([1,2], null)',
] as $c) {
    echo $c, ' => ';
    try {
        var_export(eval('return '.$c.';'));
    } catch (Throwable $e) {
        echo get_class($e), ':', $e->getMessage();
    }
    echo PHP_EOL;
}
