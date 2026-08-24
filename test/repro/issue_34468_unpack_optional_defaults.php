<?php

declare(strict_types=1);

// Follow-up #34468: missing unpack slots must use callee defaults (not null→0).
class D
{
    public int $a;
    public int $b;

    public function __construct(int $a, int $b = 9)
    {
        $this->a = $a;
        $this->b = $b;
    }
}

echo (new D(...[4]))->a, '-', (new D(...[4]))->b, "\n";
$x = 4;
$a = [$x];
$d = new D(...$a);
echo $d->a, '-', $d->b, "\n";

function g(int $a, int $b = 9): void
{
    echo $a, '-', $b, "\n";
}
$y = 7;
$g = [$y];
g(...$g);
