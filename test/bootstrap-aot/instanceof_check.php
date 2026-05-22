<?php

declare(strict_types=1);

/**
 * Bootstrap AOT lint: instanceof in control flow (Expr_InstanceOf).
 */

namespace BootstrapAot;

class Box
{
}

function accepts(mixed $value): bool
{
    return $value instanceof Box;
}

if (accepts(new Box())) {
    echo "ok\n";
}
