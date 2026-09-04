<?php
/**
 * #36382 — array_map([Class::class, 'method'], …) AOT (ArrayMapCallbackPolicy / #1154).
 * php-src: ext/standard/array.c php_array_map()
 */
class C {
    public static function dbl($x) {
        return $x * 2;
    }
}
echo implode(',', array_map([C::class, 'dbl'], [1, 2, 3])), "\n";
