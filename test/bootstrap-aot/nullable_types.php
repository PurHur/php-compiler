<?php

declare(strict_types=1);

/**
 * Bootstrap AOT lint: nullable typed properties and parameters (self-host #212).
 */

function greet(?string $name): string
{
    if (null === $name) {
        return "Hello\n";
    }

    return 'Hello '.$name."\n";
}

echo greet(null);
