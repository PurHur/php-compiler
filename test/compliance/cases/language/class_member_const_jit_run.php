<?php

class Limits
{
    private const PRIVATE_MAX = 42;

    public function max(): int
    {
        return self::PRIVATE_MAX;
    }
}

echo (new Limits())->max();
echo "\n";
