--TEST--
DNF property type (A&B)|null accepts implementing object or null (#3094)
--FILE--
<?php
interface A {}
interface B {}
class C implements A, B {}
class Holder {
    public (A&B)|null $item;
}
$h = new Holder();
$h->item = new C();
echo $h->item === null ? 'null' : 'object';
echo "\n";
$h->item = null;
echo $h->item === null ? 'null' : 'not-null';
?>
--EXPECT--
object
null
