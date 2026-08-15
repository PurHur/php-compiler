<?php
declare(strict_types=1);
error_reporting(E_ALL);
try {
    var_export(stream_set_timeout(STDIN, null));
    echo "\n";
} catch (Throwable $e) {
    echo get_class($e) . ': ' . $e->getMessage() . "\n";
}
