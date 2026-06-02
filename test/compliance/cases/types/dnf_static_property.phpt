--TEST--
DNF static property (A&B)|null (#4106)
--FILE--
<?php
interface A {}
interface B {}
class C implements A, B {}
class Holder {
    public static (A&B)|null $item;
}
Holder::$item = new C();
echo Holder::$item === null ? 'null' : 'object';
?>
--EXPECT--
object
