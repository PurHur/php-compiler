<?php

declare(strict_types=1);

if (!function_exists('nextafter')) {
    fwrite(STDERR, "fail: nextafter not registered\n");
    exit(1);
}

echo nextafter(1.0, 2.0) > 1.0 ? "ok\n" : "fail\n";
