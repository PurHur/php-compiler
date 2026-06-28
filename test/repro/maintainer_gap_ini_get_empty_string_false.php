<?php

declare(strict_types=1);

/**
 * Repro #12915 — unset security/path ini directives return '' not false (ext/standard/ini.c).
 */

$checks = [
    'disable_functions' => '',
    'disable_classes' => '',
    'open_basedir' => '',
    'mail.add_x_header' => '',
    'user_ini.filename' => '.user.ini',
    'error_append_string' => '',
    'error_prepend_string' => '',
];

foreach ($checks as $key => $expected) {
    $value = ini_get($key);
    if ('string' !== gettype($value)) {
        echo "fail: {$key} expected string, got ".gettype($value)."\n";
        exit(1);
    }
    if ($value !== $expected) {
        echo "fail: {$key} expected ".var_export($expected, true).', got '.var_export($value, true)."\n";
        exit(1);
    }
    echo $key.':'.('' === $expected ? 'empty' : 'default')."\n";
}

if (false !== ini_get('bogus_xyz_123')) {
    echo "fail: unknown ini key must return false\n";
    exit(1);
}
echo "bogus-false\n";
echo "ok\n";
