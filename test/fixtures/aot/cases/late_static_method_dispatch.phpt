--TEST--
AOT: static::method() late static binding dispatches to subclass override (#24169)
--FILE--
<?php
class BaseLsbMethod
{
    public static function make(): string
    {
        return static::who() . self::who();
    }

    public static function who(): string
    {
        return '1';
    }
}
class ChildLsbMethod extends BaseLsbMethod
{
    public static function who(): string
    {
        return '2';
    }
}
echo ChildLsbMethod::make(), "\n";
echo BaseLsbMethod::make(), "\n";
--EXPECT--
21
11
