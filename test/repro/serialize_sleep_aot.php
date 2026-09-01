<?php

declare(strict_types=1);

class GoodSleep
{
    public $a = 1;
    public $b = 2;

    public function __sleep()
    {
        return ['a'];
    }
}

echo serialize(new GoodSleep()), "\n";

class BadSleep
{
    public function __sleep()
    {
        return 1;
    }
}

echo serialize(new BadSleep()), "\n";
