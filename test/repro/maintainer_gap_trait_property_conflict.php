<?php

declare(strict_types=1);

trait T
{
    public $x = 1;
}

class C
{
    use T;

    public $x = 2;
}

echo "unreachable\n";
