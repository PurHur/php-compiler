<?php

declare(strict_types=1);

function f(array $m): int
{
    return $m['a'];
}

echo f(['a' => 1]), "\n";
