<?php
// Issue #29187 — Zend 8.5 ParseError on brace form; parenthesized form remains valid.
class C
{
    public function __construct(public int $x, public int $y = 0)
    {
    }
}

$o = new C(1, 2);
$n = clone $o with { x: 9 };
echo $n->x, '|', $n->y;
