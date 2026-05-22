<?php

declare(strict_types=1);

/**
 * Bootstrap AOT lint fixture for try/catch CFG lowering (issue #57).
 */
function bootstrap_try_catch(): int
{
    try {
        return 1;
    } catch (\Throwable $e) {
        return 2;
    }
}
