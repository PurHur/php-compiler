<?php

declare(strict_types=1);

// @differential-repeat: 3 strlen literal no-throw elision must match Zend (#36386)

function work(int $n): int
{
    $s = 0;
    for ($i = 0; $i < $n; ++$i) {
        $s += strlen('xy');
        $s += strlen('z');
    }

    return $s;
}

echo work(100), "\n";
echo strlen('hello'), "\n";
