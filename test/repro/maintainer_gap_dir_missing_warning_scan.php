<?php

declare(strict_types=1);

/**
 * Issue #11568 — opendir()/scandir() missing path must E_WARNING before false.
 *
 * php-src: ext/standard/dir.c
 */
$path = '/no/such/maintainer_gap_dir_'.getmypid();

$w = 0;
set_error_handler(static function () use (&$w): bool {
    ++$w;

    return true;
});
$r = opendir($path);
echo 'opendir warnings=', $w, ' result=', var_export($r, true), "\n";

$w = 0;
$r = scandir($path);
echo 'scandir warnings=', $w, ' result=', var_export($r, true), "\n";
