<?php
/** Repro for #31967 — array literal stored to a static property. */
class C {
    public static $a = [1];
}
echo C::$a[0];
