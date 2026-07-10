<?php

declare(strict_types=1);

function takesCallable(callable $cb): void
{
}

try {
    takesCallable(1);
    echo "fail: expected TypeError\n";
} catch (TypeError $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
