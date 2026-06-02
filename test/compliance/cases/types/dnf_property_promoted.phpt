--TEST--
DNF promoted property (A&B)|null (#4106)
--FILE--
<?php
interface A {}
interface B {}
class C implements A, B {}
class Holder {
    public function __construct(
        public (A&B)|null $item = null,
    ) {}
}
$h = new Holder(new C());
echo $h->item === null ? 'null' : 'object';
?>
--EXPECT--
object
