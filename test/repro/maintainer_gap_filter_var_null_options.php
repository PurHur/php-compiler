<?php
/** Repro for #31209 — filter_var(..., null) $options under strict_types → TypeError. */
declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '1');

try {
    var_export(filter_var('1', FILTER_VALIDATE_INT, null));
    echo "\n";
} catch (Throwable $e) {
    echo get_class($e).':'.$e->getMessage()."\n";
}
