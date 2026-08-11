<?php
declare(strict_types=1);
try {
    $r = finfo_open(null);
    echo 'NO_THROW ', gettype($r), "\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
