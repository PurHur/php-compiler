<?php
/**
 * Issue #10558 — arrow fn capturing $this invoked outside object context.
 *
 * Zend: Error: Using $this when not in object context
 * VM (before fix): NULL
 */
try {
    $r = (fn() => $this)();
    var_dump($r);
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}

class C {
    public function test(): void {
        $r = (fn() => $this)();
        echo is_object($r) ? get_class($r) : var_export($r, true), "\n";
    }
}
(new C())->test();
