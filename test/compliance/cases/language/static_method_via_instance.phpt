--TEST--
Calling a static method via instance must not prepend $this (#22288, zend_execute.c)
--FILE--
<?php
class Base {
    public static function tag(string $s): string
    {
        return static::class . ':' . $s;
    }
}
class Child extends Base {}

$c = new Child();
echo $c->tag('x'), "\n";
echo Child::tag('y'), "\n";
echo Base::tag('z'), "\n";
?>
--EXPECT--
Child:x
Child:y
Base:z
