<?php

declare(strict_types=1);

try {
    fopen(null, 'r');
    echo "uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
