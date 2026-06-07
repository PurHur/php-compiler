<?php
$o = new class(42) {
    public function __construct(private int $x) {}
    public function get(): int { return $this->x; }
};
echo $o->get(), "\n";
