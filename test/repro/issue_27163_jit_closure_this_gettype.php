<?php
/** #27163 — JIT gettype($this) in auto-bound Closure. */
class A {
    public function f() {
        $c = function () {
            return gettype($this);
        };

        return $c();
    }
}
var_export((new A())->f());
echo "\n";
