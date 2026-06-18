<?php
/**
 * Repro for #9683 — static property hook get/set on uninitialized typed backing (Zend/zend_property_hooks.c).
 */
class C {
    public static int $n {
        get => self::$n ?? 0;
        set (int $v) { self::$n = $v; }
    }
}
echo C::$n, "\n";
C::$n = 5;
echo C::$n, "\n";
