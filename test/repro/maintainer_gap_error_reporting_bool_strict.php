<?php
declare(strict_types=1);

try {
    error_reporting(false);
    echo "no_error\n";
} catch (TypeError $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
