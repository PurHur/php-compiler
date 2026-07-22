--TEST--
Language: self:: static call preserves late-static scope for static::class (#21983)
--FILE--
<?php
class ParentSelfLsb {
    public static function who(): string {
        return static::class;
    }
    public static function test(): string {
        return self::who() . '-' . static::who();
    }
    public static function named(): string {
        return ParentSelfLsb::who() . '-' . static::who();
    }
}
class ChildSelfLsb extends ParentSelfLsb {}

echo ParentSelfLsb::test(), "\n";
echo ChildSelfLsb::test(), "\n";
echo ChildSelfLsb::named(), "\n";
--EXPECT--
ParentSelfLsb-ParentSelfLsb
ChildSelfLsb-ChildSelfLsb
ParentSelfLsb-ChildSelfLsb
