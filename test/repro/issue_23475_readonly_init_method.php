<?php
/**
 * Issue #23475 — first readonly init from instance method (Zend zend_readonly.c).
 */
class C {
    public readonly int $x;
    public function set(int $v): void { $this->x = $v; }
}
$c = new C();
try {
    var_export($c->x);
    echo "\n";
} catch (Throwable $e) {
    echo 'read:', get_class($e), ':', $e->getMessage(), "\n";
}
try {
    $c->set(1);
    echo 'set_ok:', $c->x, "\n";
} catch (Throwable $e) {
    echo 'set:', get_class($e), ':', $e->getMessage(), "\n";
}
