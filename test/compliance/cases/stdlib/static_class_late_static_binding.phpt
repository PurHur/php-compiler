--TEST--
static::class late-static binding under AOT (inherited method, #20251 / #19614)
--FILE--
<?php
class StaticClassLsbA {
    public static function name(): string {
        return static::class;
    }
}
class StaticClassLsbB extends StaticClassLsbA {}
echo StaticClassLsbB::name(), "\n";
--EXPECT--
StaticClassLsbB
