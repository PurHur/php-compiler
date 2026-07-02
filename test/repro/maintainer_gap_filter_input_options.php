<?php

declare(strict_types=1);

$_GET = [];

try {
    $result = filter_input(INPUT_GET, 'missing', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    var_export($result);
    echo "\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
    exit(1);
}

echo "ok\n";
