<?php
// Repro #24169: static::method() must dispatch to the called class override (LSB), not the parent.
class B
{
    public static function m(): string
    {
        return static::w();
    }

    public static function w(): string
    {
        return '1';
    }
}
class C extends B
{
    public static function w(): string
    {
        return '2';
    }
}
echo C::m(), "\n";
echo B::m(), "\n";
