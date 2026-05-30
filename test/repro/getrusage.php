<?php
if (!function_exists('getrusage')) {
    fwrite(STDERR, "MISSING: getrusage\n");
    exit(1);
}
$u = getrusage();
echo isset($u['ru_utime.tv_sec']) || isset($u['ru_utime_usec']) ? "ok\n" : "bad shape\n";
