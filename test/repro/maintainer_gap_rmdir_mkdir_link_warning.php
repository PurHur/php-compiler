<?php

declare(strict_types=1);

/**
 * Issue #11566 — rmdir()/mkdir()/link() missing path must E_WARNING before false.
 *
 * php-src: ext/standard/file.c, link.c
 */
$base = '/no/such/maintainer_gap_fs_'.getmypid();

$w = 0;
set_error_handler(static function () use (&$w): bool {
    ++$w;

    return true;
});
$r = rmdir($base.'/dir');
echo 'rmdir warnings=', $w, ' result=', var_export($r, true), "\n";

$w = 0;
$r = mkdir($base.'/a/b/c');
echo 'mkdir warnings=', $w, ' result=', var_export($r, true), "\n";

$w = 0;
$r = link($base.'/src', $base.'/dst');
echo 'link warnings=', $w, ' result=', var_export($r, true), "\n";
