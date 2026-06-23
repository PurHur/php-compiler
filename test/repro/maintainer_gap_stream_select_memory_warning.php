<?php

// Maintainer gap / issue #10613 — stream_select() php://memory warns before ValueError.
$r = [];
$w = [fopen('php://memory', 'r+')];
$e = [];
$warnings = [];
set_error_handler(static function (int $severity, string $message) use (&$warnings): bool {
    $warnings[] = $message;

    return true;
});
try {
    stream_select($r, $w, $e, 0);
} catch (Throwable $ex) {
    echo get_class($ex), "\n";
}
echo 'warnings=', count($warnings), "\n";
