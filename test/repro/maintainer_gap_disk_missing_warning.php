<?php

declare(strict_types=1);

/**
 * Issue #11567 — disk_free_space()/disk_total_space() missing path must E_WARNING.
 *
 * php-src: ext/standard/filestat.c
 */
$path = '/no/such/maintainer_gap_disk_'.getmypid();

$w = 0;
set_error_handler(static function () use (&$w): bool {
    ++$w;

    return true;
});
$r = disk_free_space($path);
echo 'disk_free_space warnings=', $w, ' result=', var_export($r, true), "\n";

$w = 0;
$r = disk_total_space($path);
echo 'disk_total_space warnings=', $w, ' result=', var_export($r, true), "\n";
