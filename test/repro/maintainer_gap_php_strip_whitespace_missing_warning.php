<?php

// Issue #12094 — php_strip_whitespace() missing path must E_WARNING + ''.
$warnings = [];
set_error_handler(static function (int $severity, string $message) use (&$warnings): bool {
    $warnings[] = $message;

    return true;
});
$r = php_strip_whitespace('/nonexistent/maintainer_gap_php_strip_whitespace.php');
echo var_export($r, true), "\n";
echo 'warnings=', count($warnings), "\n";
if ($warnings) {
    echo str_contains($warnings[0], 'Failed to open stream') ? "warn_ok\n" : "warn_bad\n";
}
