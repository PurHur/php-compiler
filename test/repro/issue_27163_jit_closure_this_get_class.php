<?php
/**
 * #27163 — JIT get_class($this) in auto-bound Closure.
 */
class A {
    public function f() {
        $c = function () {
            return get_class($this);
        };

        return $c();
    }
}
var_export((new A())->f());
echo "\n";
