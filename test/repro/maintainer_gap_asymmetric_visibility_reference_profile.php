<?php

declare(strict_types=1);

class C
{
    private(set) string $x = 'a';
}

$c = new C();
echo $c->x, "\n";
