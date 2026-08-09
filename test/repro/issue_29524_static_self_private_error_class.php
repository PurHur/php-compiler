<?php
/**
 * #29524 — child self::$private Error names the fetch class (C), not declarer (P).
 * php-src: Zend/zend_object_handlers.c (static property fetch CE in Error).
 */
error_reporting(E_ALL);

class P
{
    private static $x = 1;

    public static function get()
    {
        return self::$x;
    }
}

class C extends P
{
    public static function trySelf()
    {
        try {
            return self::$x;
        } catch (Throwable $e) {
            echo 'self:', $e->getMessage(), "\n";

            return null;
        }
    }

    public static function tryP()
    {
        try {
            return P::$x;
        } catch (Throwable $e) {
            echo 'P:', $e->getMessage(), "\n";

            return null;
        }
    }

    public static function tryParent()
    {
        try {
            return parent::$x;
        } catch (Throwable $e) {
            echo 'parent:', $e->getMessage(), "\n";

            return null;
        }
    }

    public static function tryStatic()
    {
        try {
            return static::$x;
        } catch (Throwable $e) {
            echo 'static:', $e->getMessage(), "\n";

            return null;
        }
    }
}

echo 'P=', P::get(), "\n";
C::trySelf();
C::tryP();
C::tryParent();
C::tryStatic();
