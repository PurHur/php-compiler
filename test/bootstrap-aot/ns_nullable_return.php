<?php

declare(strict_types=1);

namespace BootstrapTest;

function maybeGreet(?string $name): ?string
{
    if (null === $name) {
        return null;
    }

    return 'Hello '.$name;
}

function caller(): void
{
    echo maybeGreet(null) === null ? 'null' : 'ok';
    echo "\n";
    echo maybeGreet('world')."\n";
}

caller();
