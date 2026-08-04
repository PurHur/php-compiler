<?php
/**
 * AOT __callStatic must pack user args as a real array (#27517).
 * php-src: Zend/zend_object_handlers.c zend_std_call_user_call
 */
class C {
    public static function __callStatic($n, $a) {
        return "CS:$n:" . count($a) . ":" . ($a[0] ?? "?");
    }
}
echo C::missing(1, 2), "\n";
