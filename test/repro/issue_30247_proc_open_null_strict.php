<?php
declare(strict_types=1);
try {
    $p = proc_open(null, [], $pipes);
    var_export($p);
    echo " NO_THROW\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
