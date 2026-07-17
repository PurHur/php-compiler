<?php
/**
 * #20251 — forward_static_call to overridden parent must run parent body (no recursion).
 */
class A
{
    public static function f(): string
    {
        return 'A';
    }
}
class B extends A
{
    public static function f(): string
    {
        return forward_static_call(['A', 'f']);
    }
}
echo B::f(), "\n";

class A2
{
    public static function f(): string
    {
        return static::class . '-A';
    }
}
class B2 extends A2
{
    public static function f(): string
    {
        return forward_static_call([A2::class, 'f']);
    }
}
echo B2::f(), "\n";

class A3
{
    public static function f(): string
    {
        return static::class . '-arr';
    }
}
class B3 extends A3
{
    public static function f(): string
    {
        return forward_static_call_array([A3::class, 'f'], []);
    }
}
echo B3::f(), "\n";
