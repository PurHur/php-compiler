<?php

declare(strict_types=1);

try {
    ini_get_all(false);
    echo "no_error\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}

$all = ini_get_all();
echo isset($all['display_errors']) ? "all_ok\n" : "all_fail\n";
$flat = ini_get_all(null, false);
echo is_string($flat['display_errors']) ? "flat_ok\n" : "flat_fail\n";
$std = ini_get_all('standard');
echo is_array($std) ? "standard_ok\n" : "standard_fail\n";
