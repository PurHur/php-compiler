<?php
// Issue #9877 — promoted constructor int parameter with identity set hook (PHP 8.4).
class C {
    public function __construct(
        public int $x {
            get => $this->x;
            set => $this->x = $value;
        }
    ) {}
}
$c = new C(1);
echo $c->x, "\n";
