<?php
// Readonly properties (PHP 8.1) — passes AOT; this locks the coverage in.
class Point {
    public function __construct(public readonly int $x, public readonly int $y) {}
    public function sum(): int { return $this->x + $this->y; }
}
$p = new Point(3, 4);
echo $p->sum(), ' ', $p->x, "\n";
