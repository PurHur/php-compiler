<?php

declare(strict_types=1);

if (!function_exists('nextafter')) {
    fwrite(STDERR, "fail: function_exists('nextafter') false on 8.4.0-dev profile\n");
    exit(1);
}

$result = nextafter(1.0, 2.0);
if (!is_float($result) || $result <= 1.0) {
    fwrite(STDERR, 'fail: nextafter(1.0, 2.0) expected float > 1.0, got '.var_export($result, true)."\n");
    exit(1);
}

echo "ok\n";
