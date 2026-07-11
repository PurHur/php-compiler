<?php
/**
 * Issue #12868 — class use self as trait must compile-fatal, not OOM.
 *
 * Zend: Fatal error: Trait "C" not found
 */
class C {
    use C {
        foo as private bar;
    }

    public function foo(): void {}
}
