<?php
final class Box {
    public $a; public $b;
    public function __construct($a, $b) { $this->a = $a; $this->b = $b; }
}
function mk(int $n): int { return $n + 1; }
function build(int $n): Box {
    return new Box(mk($n), mk($n + 1));
}
$b = build(1);
echo $b->a, '|', $b->b, "\n";
