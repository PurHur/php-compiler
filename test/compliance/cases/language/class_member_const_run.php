<?php

class Limits
{
    public const OPEN_MAX = 100;
    private const HIDDEN_MAX = 200;

    public function openMax(): int
    {
        return self::OPEN_MAX;
    }

    public function hiddenMax(): int
    {
        return self::HIDDEN_MAX;
    }
}

$limits = new Limits();
echo $limits->openMax();
echo "\n";
echo $limits->hiddenMax();
echo "\n";
echo Limits::OPEN_MAX;
echo "\n";
