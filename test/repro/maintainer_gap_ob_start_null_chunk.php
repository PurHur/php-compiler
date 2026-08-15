<?php
declare(strict_types=1);
error_reporting(E_ALL);
try {
    $r = ob_start(null, null);
    if ($r) {
        ob_end_clean();
    }
    var_export($r);
    echo "\n";
} catch (Throwable $e) {
    echo get_class($e) . ': ' . $e->getMessage() . "\n";
}
