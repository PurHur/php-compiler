<?php
$o = new class(1) {
    public function __construct(private int $x) {}
    public function get(): int { return $this->x; }
};
echo $o->get(), "\n";
