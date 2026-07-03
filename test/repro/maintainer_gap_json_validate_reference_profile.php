<?php

declare(strict_types=1);

if (!function_exists('json_validate')) {
    fwrite(STDERR, "FAIL: json_validate not registered on 8.4 forward profile\n");
    exit(1);
}

echo "ok\n";
