<?php
// Repro #20959 — PROFILE=8.4 typed timezone string args reject null
foreach ([
    static fn () => timezone_open(null),
    static fn () => new DateTimeZone(null),
    static fn () => date_default_timezone_set(null),
] as $fn) {
    try {
        var_export($fn());
        echo "\n";
    } catch (Throwable $e) {
        echo get_class($e), ': ', $e->getMessage(), "\n";
    }
}
