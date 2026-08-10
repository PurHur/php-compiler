<?php
declare(strict_types=1);
try {
    spl_autoload_call(null);
    echo "ok:spl_autoload_call\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
