<?php

declare(strict_types=1);

/**
 * Repro for #28131 — FILTER_THROW_ON_FAILURE + Filter\* exceptions (PROFILE=8.5).
 */
echo 'defined=', defined('FILTER_THROW_ON_FAILURE') ? 'Y' : 'N', "\n";
echo 'FilterException=', class_exists('Filter\\FilterException') ? 'Y' : 'N', "\n";
echo 'FilterFailedException=', class_exists('Filter\\FilterFailedException') ? 'Y' : 'N', "\n";
try {
    var_export(filter_var('nope', FILTER_VALIDATE_INT, FILTER_THROW_ON_FAILURE));
    echo " NO_THROW\n";
} catch (Throwable $e) {
    echo 'THROW ', $e::class, ':', $e->getMessage(), "\n";
}
echo 'ok=', var_export(filter_var('12', FILTER_VALIDATE_INT, FILTER_THROW_ON_FAILURE), true), "\n";
