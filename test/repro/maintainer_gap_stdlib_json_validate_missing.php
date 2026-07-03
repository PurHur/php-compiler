<?php

declare(strict_types=1);

// Issue #15241 — json_validate() registered on 8.4.0-dev forward profile (ext/json/php_json.c).

if (!function_exists('json_validate')) {
    fwrite(STDERR, "FAIL: json_validate() not registered on 8.4 forward profile\n");
    exit(1);
}

echo json_validate('{"a":1}') ? '1' : '0';
echo "\n";
echo json_validate('{') ? '1' : '0';
echo "\n";
