<?php

declare(strict_types=1);

/**
 * Maintainer gap: stream_get_wrappers() registration order (#14211, php-src-strict).
 *
 * Zend returns wrappers in registration order; alphabetical sort breaks parity.
 */
$wrappers = stream_get_wrappers();
$expectedFirst = 'https';
if (($wrappers[0] ?? null) !== $expectedFirst) {
    fwrite(STDERR, 'first wrapper expected '.$expectedFirst.', got '.var_export($wrappers[0] ?? null, true)."\n");
    fwrite(STDERR, 'full list: '.json_encode($wrappers)."\n");
    exit(1);
}

$sorted = $wrappers;
sort($sorted);
if ($wrappers === $sorted) {
    fwrite(STDERR, "stream_get_wrappers() appears alphabetically sorted\n");
    exit(1);
}

echo "ok\n";
