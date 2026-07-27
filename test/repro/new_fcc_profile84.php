<?php

declare(strict_types=1);

class C
{
    public function __construct(public int $x)
    {
    }
}

$f = new C(...);
echo $f(7)->x;
