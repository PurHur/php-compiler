<?php
// Ordinary PHP: interface + implements + instanceof. Uses a CLASSIC constructor deliberately —
// constructor property promotion is broken under AOT (#24008) and would mask what this tests.
interface Shape { public function area(): int; }
class Sq implements Shape {
    private int $s;
    public function __construct(int $s) { $this->s = $s; }
    public function area(): int { return $this->s * $this->s; }
}
$x = new Sq(4);
echo $x->area(), ' ', ($x instanceof Shape ? 'yes' : 'no'), "\n";
