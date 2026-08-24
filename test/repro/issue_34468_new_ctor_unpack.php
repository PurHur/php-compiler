<?php

declare(strict_types=1);

// AOT: new C(...$a) / instance method unpack must match Zend (#34468).
class C
{
    public function __construct(public int $x)
    {
    }

    public function set(int $y): void
    {
        $this->x = $y;
    }

    public function add(int $a, int $b): int
    {
        return $a + $b;
    }
}

$a = [3];
echo (new C(...$a))->x, "\n";
echo (new C(...[4]))->x, "\n";
$c = new C(0);
$c->set(...[5]);
echo $c->x, "\n";
$rest = [7];
echo (new C(0))->add(6, ...$rest), "\n";
