<?php

declare(strict_types=1);

/**
 * Former #17320 maintainer gap — separator is NOT a php-src API (#23949).
 * Expect unknown named parameter under PROFILE=8.4.
 */

$out = [];
try {
    parse_str('a=1;b=2', $out, separator: ';');
    echo "FAIL: named separator should be rejected\n";
    exit(1);
} catch (ArgumentCountError|Error $e) {
    echo "ok\n";
}
