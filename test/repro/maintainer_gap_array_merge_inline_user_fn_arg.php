<?php

declare(strict_types=1);

function f(string $a, mixed $b): void
{
    echo $a, "\n";
}

f('ok', array_merge(['a'], ['b']));

exit(0);
