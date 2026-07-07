<?php

declare(strict_types=1);

/**
 * Maintainer repro: hebrevc() registration and newline conversion (#17183, #17206).
 *
 * Run: PHP_COMPILER_PROFILE=8.3 php bin/vm.php test/repro/maintainer_gap_hebrevc_missing.php
 */

if (!function_exists('hebrevc')) {
    fwrite(STDERR, "fail: hebrevc() not registered\n");
    exit(1);
}

// ISO-8859-8 Hebrew with max_chars_per_line wrapping — hebrevc prefixes newlines with space.
$shalomOlam = "\xf9\xec\xe5\xed\x20\xf2\xe5\xec\xed";
$plain = hebrev($shalomOlam, 5);
$withNl = hebrevc($shalomOlam, 5);
if ($withNl !== str_replace("\n", " \n", $plain)) {
    fwrite(STDERR, "fail: hebrevc newline conversion mismatch\n");
    exit(1);
}

if ('' !== hebrevc('')) {
    fwrite(STDERR, "fail: hebrevc('') must be empty string\n");
    exit(1);
}

echo "ok\n";
