<?php

declare(strict_types=1);

class BadSleep
{
    public function __sleep()
    {
        return 1;
    }
}

echo serialize(new BadSleep()), "\n";
