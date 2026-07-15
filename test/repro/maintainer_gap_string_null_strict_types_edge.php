<?php
// #19114 — Z_PARAM_STR builtins must TypeError on null when caller is declare(strict_types=1).
// Run with: php bin/vm.php test/repro/maintainer_gap_string_null_strict_types_edge.php

declare(strict_types=1);

$checks = [
    'nl2br(null)' => static fn () => nl2br(null),
    'hex2bin(null)' => static fn () => hex2bin(null),
    'crc32(null)' => static fn () => crc32(null),
    'str_rot13(null)' => static fn () => str_rot13(null),
    'bin2hex(null)' => static fn () => bin2hex(null),
];

$failures = 0;
foreach ($checks as $label => $factory) {
    try {
        $factory();
        echo "$label: fail expected TypeError\n";
        ++$failures;
    } catch (TypeError $e) {
        echo "$label: strict_edge_ok\n";
    }
}

exit($failures > 0 ? 1 : 0);
