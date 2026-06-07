<?php
// Repro for #7466 — sort() on object-element arrays must compare like Zend, not LogicException.
class C {
    public int $v;
    public function __construct(int $v) {
        $this->v = $v;
    }
}
$arr = [new C(3), new C(1), new C(2)];
sort($arr);
foreach ($arr as $o) {
    echo $o->v, "\n";
}
