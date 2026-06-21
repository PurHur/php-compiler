<?php
declare(strict_types=1);

try {
    throw new Exception('x');
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
