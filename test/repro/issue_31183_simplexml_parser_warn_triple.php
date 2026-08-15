<?php

declare(strict_types=1);

error_reporting(E_ALL);
libxml_use_internal_errors(false);
$warnings = [];
set_error_handler(static function (int $severity, string $message) use (&$warnings): bool {
    if (E_WARNING === $severity) {
        $warnings[] = $message;
    }

    return true;
});
$result = simplexml_load_string('<');
restore_error_handler();

echo ($result === false) ? "false\n" : "not-false\n";
echo count($warnings), "\n";
foreach ($warnings as $w) {
    echo $w, "\n";
}

libxml_use_internal_errors(true);
libxml_clear_errors();
$sx = simplexml_load_string('<');
echo ($sx === false) ? "internal-false\n" : "internal-not-false\n";
echo 'internal-count=', count(libxml_get_errors()), "\n";
