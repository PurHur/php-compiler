<?php

declare(strict_types=1);

readonly class P
{
    public function __construct(public readonly int $x) {}
}

readonly class C extends P
{
    public function __construct(int $x, public readonly int $y)
    {
        parent::__construct($x);
    }
}

$c = new C(1, 2);
var_export([$c->x, $c->y]);
echo "\n";
