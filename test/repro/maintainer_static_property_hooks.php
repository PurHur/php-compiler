<?php
// Issue #6619 — static property hooks must compile-error (Zend/zend_compile.c).
class C {
    public static int $x {
        get => 1;
    }
}
echo C::$x, "\n";
