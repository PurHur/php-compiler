<?php

declare(strict_types=1);

$c = function (): int {
    return $this->x;
};

class X
{
    public int $x = 5;
}

echo Closure::bind($c, new X(), null)(), PHP_EOL;
echo $c->bindTo(new X(), null)(), PHP_EOL;
