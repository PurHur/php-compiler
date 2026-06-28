<?php

declare(strict_types=1);

/**
 * Repro #12928 — parse_ini_string() ${ENV} interpolation in double-quoted values.
 */

$r = parse_ini_string('a="${x}"'."\n".'b=2');
if (!\is_array($r)) {
    echo 'fail: expected array, got '.var_export($r, true)."\n";
    exit(1);
}
if (!\array_key_exists('a', $r)) {
    echo 'fail: missing key a, got '.var_export($r, true)."\n";
    exit(1);
}
if ('' !== $r['a']) {
    echo 'fail: a expected empty string, got '.var_export($r['a'], true)."\n";
    exit(1);
}
if (($r['b'] ?? null) !== '2') {
    echo 'fail: b expected 2, got '.var_export($r['b'] ?? null, true)."\n";
    exit(1);
}

putenv('PHP_COMPILER_INI_ENV_TEST=hello');
$r2 = parse_ini_string('a="${PHP_COMPILER_INI_ENV_TEST}"');
if (($r2['a'] ?? null) !== 'hello') {
    echo 'fail: setenv expected hello, got '.var_export($r2['a'] ?? null, true)."\n";
    exit(1);
}
putenv('PHP_COMPILER_INI_ENV_TEST');

echo "ok\n";
