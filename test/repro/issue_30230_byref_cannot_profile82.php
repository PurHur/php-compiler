<?php
// #30230: PROFILE≤8.2 by-ref Error wording is "cannot"; ≥8.4 keeps "could not" (#29624).
error_reporting(E_ALL);

foreach (['array_shift', 'array_pop'] as $fn) {
    try {
        $fn([1, 2]);
        echo "$fn: no throw\n";
    } catch (Throwable $e) {
        echo $fn, ': ', $e->getMessage(), "\n";
    }
}
