--TEST--
language: anonymous class with constructor arguments (issue #6881)
--FILE--
<?php
$o = new class(1) {
    public function __construct(private int $x) {}
    public function get(): int { return $this->x; }
};
echo $o->get(), "\n";

$p = new class(42) {
    public function __construct(private int $x) {}
    public function get(): int { return $this->x; }
};
echo $p->get(), "\n";
--EXPECT--
1
42
