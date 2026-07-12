<?php
declare(strict_types=1);

try {
    get_class_methods('NoSuch');
    echo "no throw\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
