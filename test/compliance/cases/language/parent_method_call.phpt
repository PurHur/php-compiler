--TEST--
parent::method() dispatches to parent implementation (issue #1858)
--FILE--
<?php
class Parent_ {
    public function greet(): string {
        return 'parent';
    }
}
class Child extends Parent_ {
    public function run(): void {
        echo parent::greet();
    }
}
$c = new Child();
$c->run();
echo "\n";
--EXPECT--
parent
