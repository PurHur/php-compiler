<?php
// Issue #15365: readonly class clone + __clone reinit (zend_readonly.c, PHP 8.3+ amendments)
readonly class R {
    public function __construct(public int $x) {}
    public function __clone(): void {
        $this->x = 5;
    }
}
$r = new R(1);
$c = clone $r;
echo 'clone_x=', $c->x, "\n";
