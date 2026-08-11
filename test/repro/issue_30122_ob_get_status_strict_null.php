<?php
declare(strict_types=1);
error_reporting(E_ALL);
try {
    var_export(ob_get_status(null));
    echo "\n";
} catch (Throwable $e) {
    echo get_class($e).': '.$e->getMessage()."\n";
}
