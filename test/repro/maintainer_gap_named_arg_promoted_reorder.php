<?php
class C {
    public function __construct(public int $a, public int $b = 0) {}
}
$c = new C(b: 2, a: 1);
echo $c->a, "\n", $c->b, "\n";
