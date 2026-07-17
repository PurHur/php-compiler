<?php
/**
 * #20251 AOT-friendly repro — string Class::method callables (array form is pre-existing AOT gap).
 */
class FscBase
{
    public static function target(): string
    {
        return 'base';
    }

    public static function forwarder(): string
    {
        return forward_static_call('FscBase::target');
    }
}
class FscChild extends FscBase
{
    public static function target(): string
    {
        return 'child';
    }
}
echo FscChild::forwarder(), "\n";

class FscOwnerA
{
    public static function f(): string
    {
        return 'A';
    }
}
class FscOwnerB extends FscOwnerA
{
    public static function f(): string
    {
        return forward_static_call('FscOwnerA::f');
    }
}
echo FscOwnerB::f(), "\n";

class FscLsbA
{
    public static function f(): string
    {
        return static::class . '-A';
    }
}
class FscLsbB extends FscLsbA
{
    public static function f(): string
    {
        return forward_static_call('FscLsbA::f');
    }
}
echo FscLsbB::f(), "\n";
