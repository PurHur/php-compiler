<?php
declare(strict_types=1);
error_reporting(E_ALL);
try {
    $r = get_defined_constants(null);
    echo "uncaught type=".gettype($r)."\n";
} catch (Throwable $e) {
    echo get_class($e).': '.$e->getMessage()."\n";
}
