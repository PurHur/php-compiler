<?php
declare(strict_types=1);

// Issue #15816 — consecutive ?: operands as multi-arg call args must bind independently.

function pair(string $a, int $b): void
{
    echo "a={$a} b={$b}\n";
}

pair(true ? 'yes' : 'no', false ? 1 : 2);
echo sprintf("%s-%d", true ? 'yes' : 'no', false ? 1 : 2), "\n";
$x = range(1, 14);
var_dump($x === false ? 'false' : 'array', is_array($x) ? count($x) : null);
