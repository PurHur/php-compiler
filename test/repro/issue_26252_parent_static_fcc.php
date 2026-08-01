<?php
/**
 * Issue #26252: parent::staticMethod(...) first-class callable from static context.
 * Zend prints A; previously Closure::fromCallable invalid callback.
 */
class A
{
    public static function m(): string
    {
        return 'A';
    }
}
class B extends A
{
    public static function t(): string
    {
        $f = parent::m(...);

        return $f();
    }
}
echo B::t(), "\n";
