<?php
declare(strict_types=1);
// #31264 — parse_ini_string null $process_sections / $scanner_mode under strict_types → TypeError
try {
    var_export(parse_ini_string('a=1', null));
    echo "\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
try {
    var_export(parse_ini_string('a=1', false, null));
    echo "\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
