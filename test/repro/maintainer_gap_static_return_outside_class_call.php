<?php
declare(strict_types=1);

function f(): static {
    return new static();
}
try {
    f();
    echo "called_ok\n";
} catch (Throwable $e) {
    echo get_class($e), ": ", $e->getMessage(), "\n";
}
