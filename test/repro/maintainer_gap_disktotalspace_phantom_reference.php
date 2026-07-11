<?php

declare(strict_types=1);

if (function_exists('disktotalspace')) {
    fwrite(STDERR, "fail: function_exists('disktotalspace') true on 8.2 reference profile\n");
    exit(1);
}

if (!function_exists('disk_total_space') || !function_exists('diskfreespace')) {
    fwrite(STDERR, "fail: disk_total_space/diskfreespace missing\n");
    exit(1);
}

echo "ok\n";
