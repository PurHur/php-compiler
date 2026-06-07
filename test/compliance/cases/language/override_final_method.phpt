--TEST--
Language: overriding final parent method — compile-time fatal (#4263)
--FILE--
<?php
class Base {
    final public function foo(): void {}
}
class Child extends Base {
    public function foo(): void {}
}
new Child;
echo "run\n";
--EXPECT_EXIT--
255
