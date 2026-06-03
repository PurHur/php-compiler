<?php
declare(strict_types=1);

function g(): Generator
{
    yield from 'ab';
}

try {
    foreach (g() as $_) {
    }
    echo "no throw\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
