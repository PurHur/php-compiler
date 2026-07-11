<?php

declare(strict_types=1);

readonly class C
{
    public int $x = 1;
}

echo (new C())->x, "\n";
