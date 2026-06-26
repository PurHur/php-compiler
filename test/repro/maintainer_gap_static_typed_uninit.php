<?php
/**
 * Repro for #12056 — static typed property read before initialization (Zend/zend_type.c).
 */
class C {
    public static int $x;
}
try {
    echo C::$x;
    echo "read_ok\n";
} catch (Error $e) {
    echo $e->getMessage(), "\n";
}
