<?php
/**
 * Maintainer gap #32204: catch (Exception $this) is accepted.
 * Zend: compile fatal "Cannot re-assign $this" (rc=255).
 * php-src: Zend/zend_compile.c zend_compile_catch().
 */
class C {
    public function m(): void {
        try {
            throw new Exception('x');
        } catch (Exception $this) {
            echo "accepted\n";
        }
    }
}
(new C())->m();
