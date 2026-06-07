<?php
// Issue #7313 — promoted constructor parameters with property hooks (PHP 8.4).
class C {
    public function __construct(
        public string $name {
            get => strtoupper($this->name);
            set => $this->name = strtolower($value);
        },
    ) {}
}
$c = new C('AbC');
echo $c->name, "\n";
