<?php
declare(strict_types=1);
$f = fopen('php://memory', 'r');
try {
    stream_get_contents($f, null, null);
    echo "NO_THROW\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
