<?php
class Box {
    public array $a;
    public function __construct(array $a) { $this->a = $a; }
}
function test($s = new Box([1, 2])): void {
    echo "ok count=", count($s->a), "\n";
}
test();
