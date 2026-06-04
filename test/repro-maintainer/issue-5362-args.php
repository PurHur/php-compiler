<?php
class Box {
    public function __construct(public array $items = []) {}
}
class C {
    public $y = new Box([]);
}
$c = new C();
echo ($c->y instanceof Box && $c->y->items === []) ? "1\n" : "0\n";
