<?php

declare(strict_types=1);

class C
{
    public int $i;

    public function __construct(int $i)
    {
        $this->i = $i;
    }
}

$a = [new C(2), new C(1)];
$ids = [spl_object_id($a[0]), spl_object_id($a[1])];
usort($a, static fn ($x, $y) => $x->i <=> $y->i);
echo $a[0]->i, "\n";
echo spl_object_id($a[0]) === $ids[1] ? "1\n" : "0\n";
echo spl_object_id($a[1]) === $ids[0] ? "1\n" : "0\n";
