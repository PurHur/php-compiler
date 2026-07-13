<?php

declare(strict_types=1);

try {
    $n = fwrite(STDERR, 42);
    echo 'wrote=', $n, "\n";
    exit(1);
} catch (TypeError $e) {
    echo 'TypeError:', str_contains($e->getMessage(), 'string') ? 'yes' : 'no', "\n";
}

$n2 = fwrite(STDERR, 'ok');
echo 'string_ok=', $n2, "\n";
