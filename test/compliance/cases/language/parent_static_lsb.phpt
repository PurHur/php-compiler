--TEST--
Language: parent:: static call preserves late-static scope for static::class (#12245)
--FILE--
<?php
class ParentLsb {
    public static function who(): string {
        return static::class;
    }
}
class ChildLsb extends ParentLsb {
    public static function who(): string {
        return parent::who();
    }
    public static function selfWho(): string {
        return static::who();
    }
}
class GrandChildLsb extends ChildLsb {}

echo ChildLsb::who(), "\n";
echo GrandChildLsb::who(), "\n";
echo ParentLsb::who(), "\n";
echo ChildLsb::selfWho(), "\n";
--EXPECT--
ChildLsb
GrandChildLsb
ParentLsb
ChildLsb
