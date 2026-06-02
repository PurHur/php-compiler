--TEST--
Language: abstract static methods — dispatch and inheritance (#4312)
--FILE--
<?php
abstract class Base {
    abstract public static function make(): string;
}
class Child extends Base {
    public static function make(): string { return 'ok'; }
}
echo Child::make(), "\n";
echo (new Child())::make(), "\n";
--EXPECT--
ok
ok
