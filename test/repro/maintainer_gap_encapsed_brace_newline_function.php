<?php

declare(strict_types=1);

function ok(string $label): void
{
    echo "ok {$label}\n";
}

ok('test');
