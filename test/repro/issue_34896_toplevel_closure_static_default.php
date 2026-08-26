<?php
// Issue #34896 — top-level closure reads class static default (AOT undeclared leftover of #34868)

class C34896
{
    public static $x = 42;
}

$f = function () {
    return C34896::$x;
};

var_dump($f());
