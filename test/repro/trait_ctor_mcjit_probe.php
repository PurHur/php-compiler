<?php
trait HasX {
    public function __construct(public int $x) {}
}
class C {
    use HasX;
}
$c = new C(3);
echo $c->x, "\n";
