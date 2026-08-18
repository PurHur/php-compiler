<?php
/**
 * Maintainer gap #32205: foreach ($a as &$this) is accepted.
 * Zend: compile fatal "Cannot re-assign $this" (rc=255).
 * php-src: Zend/zend_compile.c zend_compile_foreach().
 */
class C {
    public function m(): void {
        $a = [1];
        foreach ($a as &$this) {
            echo "accepted\n";
        }
    }
}
(new C())->m();
