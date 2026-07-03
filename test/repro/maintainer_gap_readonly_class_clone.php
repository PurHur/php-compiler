<?php
// Issue #15365 / #15409: readonly class clone + __clone (zend_readonly.c)
readonly class R {
    public function __construct(public int $x) {}
    public function __clone(): void {
        $this->x = 5;
    }
}
$r = new R(1);
$c = clone $r;
echo 'clone_x=', $c->x, "\n";
