<?php
declare(strict_types=1);
error_reporting(E_ALL);
foreach (['get_defined_constants', 'get_defined_functions', 'get_loaded_extensions'] as $fn) {
    try {
        $r = $fn(null);
        echo "$fn: ran type=".gettype($r)."\n";
    } catch (Throwable $e) {
        echo "$fn: ".get_class($e).': '.$e->getMessage()."\n";
    }
}
$n = null;
try {
    get_defined_constants($n);
    echo "var: ran\n";
} catch (Throwable $e) {
    echo 'var: '.get_class($e).': '.$e->getMessage()."\n";
}
