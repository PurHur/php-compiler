<?php

declare(strict_types=1);

/**
 * Repro for #10764 — stripslashes() removes any backslash before a byte (php-src stripslashes.c).
 */

$out = stripslashes('a\\b');
if ('ab' !== $out) {
    fwrite(STDERR, "FAIL: expected 'ab', got " . var_export($out, true) . "\n");
    exit(1);
}

if ('610062' !== bin2hex(stripslashes('a\\0b'))) {
    fwrite(STDERR, "FAIL: \\0 unescape expected a\\0b\n");
    exit(1);
}

echo "PASS\n";
