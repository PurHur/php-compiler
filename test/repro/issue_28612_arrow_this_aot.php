<?php
// #28612: AOT arrow/closure reading $this must bind the enclosing object (not segfault).
class A {
    private int $x = 5;
    function f() {
        $g = fn() => $this->x;
        return $g();
    }
}
echo (new A)->f(), "\n";
