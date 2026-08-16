<?php
/**
 * Repro: highlight_file()/show_source("") — E_WARNING then ValueError (#30514).
 *
 * ./script/docker-exec.sh -- bash -lc 'php bin/vm.php test/repro/issue_30514_highlight_empty_path_warning.php'
 */
error_reporting(E_ALL);
ini_set('display_errors', '1');

foreach (['highlight_file', 'show_source'] as $fn) {
    $warnings = [];
    set_error_handler(static function (int $severity, string $message) use (&$warnings): bool {
        $warnings[] = $message;

        return true;
    });
    try {
        $fn('');
        echo $fn, ":NO_THROW\n";
    } catch (Throwable $e) {
        echo $fn, ':', get_class($e), ':', $e->getMessage(), "\n";
    }
    echo $fn, ':warnings=', count($warnings);
    if ($warnings) {
        echo ':', (str_contains($warnings[0], "Failed opening '' for highlighting") ? 'ok' : 'bad');
    }
    echo "\n";
    restore_error_handler();
}
