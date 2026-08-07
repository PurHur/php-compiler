<?php
/**
 * Issue #28564 — clone(new C(...), [...]) must parse under PROFILE=8.5.
 * Expect: 2,a
 */
class C
{
    public function __construct(public int $x = 1, public string $y = 'a')
    {
    }
}
$c = clone(new C(1, 'a'), ['x' => 2]);
echo $c->x, ',', $c->y, "\n";
