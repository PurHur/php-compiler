<?php
/** Repro for #32036 — `$r = &C::$x` must bind the static slot (zend_variables.c). */
class C
{
    public static $x = 0;
}

$r = &C::$x;
$r = 99;
echo C::$x, "\n";
