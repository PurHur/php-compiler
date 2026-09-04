<?php

/**
 * AOT (#36382): LSB static::$bool when two classes declare the same typed bool
 * property name (Slim AppFactory + ServerRequestCreatorFactory). Zend returns
 * the called class's value; broken AOT emitted `load void, i1` (double-load of
 * KIND_VALUE) and failed module verify.
 *
 * php-src: Zend/zend_execute.c ZEND_FETCH_STATIC_PROP_R / ZEND_ASSIGN_STATIC_PROP
 */
class FactoryA
{
    protected static bool $slimHttpDecoratorsAutomaticDetectionEnabled = true;

    public static function get(): bool
    {
        return static::$slimHttpDecoratorsAutomaticDetectionEnabled;
    }

    public static function set(bool $enabled): void
    {
        static::$slimHttpDecoratorsAutomaticDetectionEnabled = $enabled;
    }
}

class FactoryB
{
    protected static bool $slimHttpDecoratorsAutomaticDetectionEnabled = false;
}

echo FactoryA::get() ? '1' : '0';
FactoryA::set(false);
echo FactoryA::get() ? '1' : '0';
echo PHP_EOL;
