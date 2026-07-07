<?php

declare(strict_types=1);

/**
 * Maintainer repro: forward-profile builtins must not leak on unset 8.2 reference profile (#17206).
 *
 * Run: php bin/vm.php test/repro/maintainer_gap_forward_profile_builtin_leak_82.php
 */

$leaked = array_filter(
    [
        'mb_trim',
        'crc32c',
        'hebrevc',
        'attribute_exists',
        'class_uses_recursive',
    ],
    static fn (string $fn): bool => function_exists($fn)
);

if ([] !== $leaked) {
    echo 'fail: leaked on reference profile: ', implode(', ', $leaked), "\n";
    exit(1);
}

echo "ok\n";
