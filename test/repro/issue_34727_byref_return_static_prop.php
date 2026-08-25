<?php
/**
 * #34727 — AOT by-ref return of Class::$prop must alias the live module cell
 * (Zend ZEND_FETCH_STATIC_PROP_W + ZEND_RETURN_BY_REF).
 */
class C34727
{
    public static $x = 1;

    public static function &fromSelf()
    {
        return self::$x;
    }
}

function &f_static_prop()
{
    return C34727::$x;
}

echo 'fn:';
$a = &f_static_prop();
$a = 5;
var_dump(C34727::$x);

echo 'method:';
$b = &C34727::fromSelf();
$b = 9;
var_dump(C34727::$x);
