<?php
// #24086: an array property with a literal default read as EMPTY under AOT — count() gave 0 and
// element reads gave 0, private or public, inside or outside the class. Fixed; kept as a guard.
class C {
    private array $p = [1, 2];
    public array $q = [7, 8, 9];
    public function n(): int { return count($this->p); }
    public function e(): int { return $this->p[1]; }
}
$o = new C;
echo $o->n(), ' ', $o->e(), ' ', count($o->q), ' ', $o->q[2], "\n";
