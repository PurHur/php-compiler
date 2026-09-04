<?php
declare(strict_types=1);
/** @differential-repeat: 3 */
final class A {
    public function mid(int $x): int { return $this->leaf($x) + 1; }
    public function leaf(int $x): int { return $x + 1; }
    public function top(int $x): int { return $this->mid($x) + 1; }
}
$o = new A();
$s = 0;
for ($i = 0; $i < 20; ++$i) {
    $s += $o->top(1);
}
echo $s, "\n";
