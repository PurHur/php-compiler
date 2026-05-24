--TEST--
Late static binding: static::method() and static::class (issue #1231)
--FILE--
<?php
class Greeter {
    public static function tag(): string {
        return 'hi';
    }
    public function viaStatic(): string {
        return static::tag();
    }
    public function className(): string {
        return static::class;
    }
}
$g = new Greeter();
echo $g->viaStatic(), "\n";
echo $g->className(), "\n";
echo Greeter::tag(), "\n";
--EXPECT--
hi
Greeter
hi
