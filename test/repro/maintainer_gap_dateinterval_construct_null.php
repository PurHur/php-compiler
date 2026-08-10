<?php
declare(strict_types=1);
try {
    new DateInterval(null);
    echo "uncaught\n";
} catch (Throwable $e) {
    echo get_class($e).':'.$e->getMessage(),"\n";
}
