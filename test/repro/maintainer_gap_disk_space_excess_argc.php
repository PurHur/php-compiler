<?php

/**
 * #30552 — disk_*_space excess argc → ArgumentCountError (php-src filestat.c).
 */
error_reporting(E_ALL);

foreach (['disk_free_space', 'disk_total_space', 'diskfreespace'] as $fn) {
    try {
        $fn('/', 'x');
        echo "$fn excess: NO_THROW\n";
    } catch (Throwable $e) {
        echo $fn, ' excess => ', get_class($e), ': ', $e->getMessage(), "\n";
    }
    try {
        $fn();
        echo "$fn missing: NO_THROW\n";
    } catch (Throwable $e) {
        echo $fn, ' missing => ', get_class($e), ': ', $e->getMessage(), "\n";
    }
}
