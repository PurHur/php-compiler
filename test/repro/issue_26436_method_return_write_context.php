<?php
/**
 * #26436 — method/function return in write context must compile-fatal (zend_compile.c).
 *
 * Zend: Fatal error: Can't use method return value in write context
 */
class C {
    public int $x = 1;
    public function &get(): int { return $this->x; }
}
function f(): C { return new C; }
f()->get() = 2;
echo "ASSIGNED\n";
