<?php
// Issue #29187 — parenthesized clone($obj, [...]) must still work under PROFILE=8.5.
class C
{
    public function __construct(public int $x, public int $y = 0)
    {
    }
}

$o = new C(1, 2);
$n = clone($o, ['x' => 9]);
echo $n->x, '|', $n->y, "\n";
