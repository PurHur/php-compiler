--TEST--
Language: clone when __clone is protected — Error from global, ok in subclass (#5077)
--FILE--
<?php
class Base {
    protected function __clone() {}
}
class Child extends Base {
    public function dup(): void {
        clone $this;
        echo "subclass ok\n";
    }
}
$o = new Child();
try {
    clone $o;
    echo "cloned\n";
} catch (Error $e) {
    echo $e->getMessage(), "\n";
}
$o->dup();
--EXPECT--
Trying to clone an uncloneable object of class Child
subclass ok
