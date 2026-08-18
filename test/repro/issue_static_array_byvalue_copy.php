<?php
/**
 * #32307 — by-value assign of a static array must zend_array_dup, not alias the module slot.
 * Zend ZEND_FETCH_STATIC_PROP_R + zend_assign_to_variable / SEPARATE_ARRAY.
 */
class A
{
    public static $a = [1];
}
$b = A::$a;
$b[0] = 99;
var_dump(A::$a[0]);

class T
{
    public static array $a = [1];
}
$c = T::$a;
$c[0] = 99;
var_dump(T::$a[0]);

T::$a[0] = 99;
var_dump(T::$a[0]);
