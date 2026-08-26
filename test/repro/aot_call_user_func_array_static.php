<?php
// #35090 — call_user_func(['Class','method']) must match Zend under AOT (peer #32299 $c()).
class C {
    public static function f() {
        return 4;
    }
    public static function g($x) {
        return $x * 2;
    }
}
echo call_user_func(['C', 'f']), "\n";
echo call_user_func([C::class, 'g'], 21), "\n";
