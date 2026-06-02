--TEST--
AOT: extends method override and parent:: call (issue #101, Zend/zend_inheritance.c)
--FILE--
<?php
class Base {
    public function greet(): string {
        return 'base';
    }
}
class Child extends Base {
    public function greet(): string {
        return 'child';
    }
    public function viaParent(): void {
        echo parent::greet();
    }
}
$c = new Child();
echo $c->greet(), "\n";
$c->viaParent();
echo "\n";
--EXPECT--
child
base

