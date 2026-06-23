<?php

declare(strict_types=1);

/**
 * Issue #10948 — missing-path E_WARNING for fs metadata builtins.
 *
 * php-src: ext/standard/file.c, image.c; ext/zlib/zlib.c; basic_functions.c.
 *
 * Verify:
 *   php bin/vm.php test/repro/maintainer_gap_fs_meta_missing_warning.php
 *   php bin/jit.php test/repro/maintainer_gap_fs_meta_missing_warning.php
 */
$path = '/no/such/phpc-fs-meta-missing-'.getmypid();
$checks = [
    'readlink' => static fn () => readlink($path),
    'chown' => static fn () => chown($path, 0),
    'chgrp' => static fn () => chgrp($path, 0),
    'getimagesize' => static fn () => getimagesize($path),
    'gzopen' => static fn () => gzopen($path, 'r'),
    'error_log' => static fn () => error_log('msg', 3, $path),
];
foreach ($checks as $fn => $cb) {
    $warnings = [];
    set_error_handler(static function (int $severity, string $message) use (&$warnings): bool {
        $warnings[] = $message;

        return true;
    });
    $result = $cb();
    echo $fn, ' ', count($warnings), ' ', var_export($result, true), "\n";
}
