<?php

declare(strict_types=1);

// Issue #11943 — getenv(null) returns full environ array (ext/standard/basic_functions.c).
$all = getenv(null);
if (!is_array($all)) {
    echo 'fail: expected array, got ', gettype($all), "\n";
    exit(1);
}
if (!array_key_exists('PATH', $all) && !array_key_exists('HOME', $all)) {
    echo "fail: environ missing PATH/HOME\n";
    exit(1);
}
echo "ok\n";
