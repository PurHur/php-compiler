<?php
/** Repro #26437 — non-public __callStatic must warn and still dispatch (Zend php-src-strict). */
error_reporting(E_ALL);
class C {
    private static function __callStatic($n, $a) {
        return "cs:$n";
    }
}
echo C::foo(), PHP_EOL;
