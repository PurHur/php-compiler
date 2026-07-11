<?php

declare(strict_types=1);

// Issue #17881 — get_cfg_var() must not mirror ini_get() runtime values.
$checks = [
    'extension_dir' => false,
    'error_log' => false,
    'upload_tmp_dir' => false,
    'cfg_file_path' => 'string',
    'does_not_exist' => false,
];

foreach ($checks as $key => $expect) {
    $actual = get_cfg_var($key);
    if ('string' === $expect) {
        if (!is_string($actual) || '' === $actual) {
            fwrite(STDERR, "fail: get_cfg_var({$key}) expected non-empty string, got ".var_export($actual, true)."\n");
            exit(1);
        }
        continue;
    }
    if ($actual !== $expect) {
        fwrite(STDERR, "fail: get_cfg_var({$key}) expected ".var_export($expect, true).', got '.var_export($actual, true)."\n");
        exit(1);
    }
}

// ini_get() unchanged — runtime path still visible.
if ('' !== ini_get('error_log')) {
    fwrite(STDERR, 'fail: ini_get(error_log) expected empty string'."\n");
    exit(1);
}

echo "ok\n";
