<?php

declare(strict_types=1);

function sample($alpha, $bravo = 1): void
{
}

echo implode(',', (new ReflectionFunction('sample'))->getNamedArguments()), "\n";

class SampleMethodHost
{
    public function m($x, $y): void
    {
    }
}

echo implode(',', (new ReflectionMethod(SampleMethodHost::class, 'm'))->getNamedArguments()), "\n";
echo implode(',', (new ReflectionFunction('strlen'))->getNamedArguments()), "\n";
