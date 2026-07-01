<?php

// Issue #14641 — null path operands: deprecation only, no stat/lstat failed warnings (ext/standard/filestat.c).
$funcs = [
    'filetype',
    'fileperms',
    'fileinode',
    'filegroup',
    'fileowner',
    'filesize',
    'filemtime',
    'stat',
    'lstat',
];
$errors = 0;
foreach ($funcs as $fn) {
    $warns = [];
    set_error_handler(static function (int $errno, string $msg) use (&$warns): bool {
        $warns[] = $msg;

        return true;
    });
    $r = $fn(null);
    restore_error_handler();
    if (false !== $r) {
        echo "fail: {$fn}(null) expected false\n";
        ++$errors;
        continue;
    }
    foreach ($warns as $w) {
        if (str_contains($w, 'failed for')) {
            echo "fail: {$fn}(null) legacy stat failed warning: {$w}\n";
            ++$errors;
        }
    }
}
echo 0 === $errors ? "ok\n" : "fail\n";
exit($errors > 0 ? 1 : 0);
