<?php
// Issue #34896 — top-level arrow reads class static default

class C34896Arrow
{
    public static $x = 42;
}

$f = fn () => C34896Arrow::$x;
var_dump($f());
