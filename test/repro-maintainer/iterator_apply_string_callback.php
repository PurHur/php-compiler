<?php
declare(strict_types=1);
function bump(int &$v): int {
    ++$v;
    return $v;
}
$total = 0;
class C implements Iterator {
    private int $i = 0;
    public function rewind(): void { $this->i = 0; }
    public function valid(): bool { return $this->i < 3; }
    public function current(): int { return $this->i + 1; }
    public function key(): int { return $this->i; }
    public function next(): void { ++$this->i; }
}
$n = iterator_apply(new C(), 'bump', [&$total]);
echo $n, ' ', $total, "\n";
