--TEST--
parent::staticMethod() dispatches on parent class (issue #1858)
--FILE--
<?php
class Parent_ {
    public static function greet(): void {
        echo 'parent';
    }
}
class Child extends Parent_ {
    public function run(): void {
        parent::greet();
    }
}
(new Child())->run();
echo "\n";
--EXPECT--
parent
