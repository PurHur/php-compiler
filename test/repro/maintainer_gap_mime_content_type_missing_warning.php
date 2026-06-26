<?php

// Issue #12096 — mime_content_type() missing path must E_WARNING + false.
$warnings = [];
set_error_handler(static function (int $severity, string $message) use (&$warnings): bool {
    $warnings[] = $message;

    return true;
});
$r = mime_content_type('/nonexistent/maintainer_gap_mime_content_type.bin');
echo var_export($r, true), "\n";
echo 'warnings=', count($warnings), "\n";
if ($warnings) {
    echo str_contains($warnings[0], 'Failed to open stream') ? "warn_ok\n" : "warn_bad\n";
}
