<?php

declare(strict_types=1);

/**
 * Maintainer repro: disktotalspace() must not exist on Zend 8.2 reference profile (issue #11922).
 *
 * php-src: ext/standard/filestat.c — disk_total_space(); no disktotalspace export on 8.2 stub.
 */

if (function_exists('disktotalspace')) {
    echo "fail: disktotalspace phantom alias registered\n";
    exit(1);
}

if (!function_exists('disk_total_space')) {
    echo "fail: disk_total_space missing\n";
    exit(1);
}

$total = disk_total_space(sys_get_temp_dir());
if (false === $total) {
    echo "fail: disk_total_space returned false\n";
    exit(1);
}

echo "ok: disktotalspace not registered\n";
