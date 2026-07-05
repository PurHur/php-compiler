<?php

declare(strict_types=1);

class C
{
    public const X = 42;
}

echo C::{'X'};
