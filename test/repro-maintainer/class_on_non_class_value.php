<?php

declare(strict_types=1);

$x = 'stdClass';
try {
    echo $x::class, "\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}

$n = 1;
try {
    echo $n::class, "\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
