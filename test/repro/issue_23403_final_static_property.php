<?php
// Issue #23403 — final static property must fatal on reference profile (Zend 8.2).
class A {
    public final static $x = 1;
}
echo A::$x, "\n";
