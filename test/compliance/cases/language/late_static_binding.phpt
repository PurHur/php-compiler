--TEST--
Language: late static binding static::method() and static::class (#1231)
--FILE--
<?php
class Base {
    public static function who(): string {
        return static::class;
    }
    public function instanceWho(): string {
        return static::class;
    }
}
class Child extends Base {}
echo Base::who(), "\n";
echo Child::who(), "\n";
echo (new Base())->instanceWho(), "\n";
echo (new Child())->instanceWho(), "\n";
--EXPECT--
Base
Child
Base
Child
