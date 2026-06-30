<?php

declare(strict_types=1);

$handle = fopen('php://memory', 'r+');
try {
    stream_get_line($handle);
    echo "no_throw\n";
} catch (ArgumentCountError $e) {
    echo get_class($e), "\n";
    echo $e->getMessage(), "\n";
}
