<?php

declare(strict_types=1);

try {
    vfprintf(STDOUT, '%d', 'x');
} catch (\TypeError $e) {
    echo 'TypeError: ', $e->getMessage(), "\n";
} catch (\Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
