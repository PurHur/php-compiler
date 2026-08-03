<?php
/** #27163 — JIT spl_object_id($this) in auto-bound Closure. */
class A {
    public function f() {
        $c = function () {
            return spl_object_id($this);
        };

        return $c();
    }
}
echo (new A())->f() > 0 ? "1\n" : "0\n";
