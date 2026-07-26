<?php

declare(strict_types=1);

/**
 * #23232 — max_memory_limit ceiling clamps memory_limit (main/main.c).
 *
 *   PHP_COMPILER_PROFILE=8.5 php bin/vm.php -d max_memory_limit=64M \
 *     test/repro/maintainer_gap_max_memory_limit_ini_85_ceiling.php
 */

$max = ini_get('max_memory_limit');
if (!is_string($max) || '64M' !== $max) {
    fwrite(STDERR, 'fail: expected -d max_memory_limit=64M, got '.var_export($max, true)."\n");
    exit(1);
}
if ('64M' !== ini_get('memory_limit')) {
    fwrite(STDERR, 'fail: startup memory_limit should match max, got '.var_export(ini_get('memory_limit'), true)."\n");
    exit(1);
}

error_reporting(E_ALL);

ini_set('memory_limit', '128M');
$last = error_get_last();
if (!is_array($last) || !str_contains((string) $last['message'], 'max_memory_limit')) {
    fwrite(STDERR, 'fail: expected E_WARNING when raising memory_limit above max, got '.var_export($last, true)."\n");
    exit(1);
}
if ('64M' !== ini_get('memory_limit')) {
    fwrite(STDERR, 'fail: memory_limit should clamp to max, got '.var_export(ini_get('memory_limit'), true)."\n");
    exit(1);
}

error_clear_last();
ini_set('memory_limit', '-1');
$last = error_get_last();
if (null !== $last && str_contains((string) $last['message'], 'max_memory_limit')) {
    fwrite(STDERR, "fail: unlimited memory_limit request must clamp without warning\n");
    exit(1);
}
if ('64M' !== ini_get('memory_limit')) {
    fwrite(STDERR, 'fail: unlimited request should clamp to max, got '.var_export(ini_get('memory_limit'), true)."\n");
    exit(1);
}

error_clear_last();
ini_set('memory_limit', '32M');
$last = error_get_last();
if (null !== $last && str_contains((string) $last['message'], 'max_memory_limit')) {
    fwrite(STDERR, "fail: lowering memory_limit under max must not warn\n");
    exit(1);
}
if ('32M' !== ini_get('memory_limit')) {
    fwrite(STDERR, 'fail: expected memory_limit=32M, got '.var_export(ini_get('memory_limit'), true)."\n");
    exit(1);
}

echo "ceiling-ok\n";
