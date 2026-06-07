<?php
declare(strict_types=1);

function g(mixed ...$rest, int $b = 1): void
{
    echo $b, "\n";
}

g(b: 2); // Zend: prints 2
