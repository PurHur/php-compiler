<?php
/** Repro for #31967 — array literal stored to a static property. */
class C {
    public static $a;
}
C::$a = ['x'];
echo C::$a[0] === 'x' ? '1' : '0';
