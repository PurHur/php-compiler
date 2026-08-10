<?php
// Issue #29673 — promoted ctor typed set(string $v) must match property type (PHP 8.4).
class C {
    public function __construct(
        public string $name {
            set(string $v) { $this->name = strtoupper($v); }
        }
    ) {}
}
$c = new C('hi');
echo $c->name, "\n";
