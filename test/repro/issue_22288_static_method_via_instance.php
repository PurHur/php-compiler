<?php
class Base {
    public static function tag(string $s): string
    {
        return static::class . ':' . $s;
    }
}
class Child extends Base {}
$c = new Child();
echo $c->tag('x'), PHP_EOL;
echo Child::tag('y'), PHP_EOL;
echo Base::tag('z'), PHP_EOL;
