<?php

declare(strict_types=1);

/**
 * Issue #6276 — apache_note()/apache_get_version() registered on CGI profile; false outside Apache.
 */

foreach (['apache_note', 'apache_get_version'] as $fn) {
    if (!\function_exists($fn)) {
        echo "fail: {$fn} not registered\n";
        exit(1);
    }
}

$note = @apache_note('probe_key', 'probe_val');
if (false !== $note) {
    echo 'fail: apache_note set got ', var_export($note, true), "\n";
    exit(1);
}

$version = @apache_get_version();
if (false !== $version) {
    echo 'fail: apache_get_version got ', var_export($version, true), "\n";
    exit(1);
}

echo "ok\n";
