<?php
class Box {
    public array $a;
    public function __construct(array $a) { $this->a = $a; }
}
function test(): void {
    static $s = new Box([1, 2]);
    echo "ok count=", count($s->a), "\n";
}
test();
