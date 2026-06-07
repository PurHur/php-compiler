<?php
declare(strict_types=1);
$c = function (): int { return 42; };
try {
    echo Closure::call($c, new stdClass());
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
