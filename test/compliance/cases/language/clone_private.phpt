--TEST--
Language: clone when __clone is private — Error from external scope (#5077)
--FILE--
<?php
class C {
    private function __clone() {}
}
$o = new C();
try {
    clone $o;
    echo "cloned\n";
} catch (Error $e) {
    echo $e->getMessage(), "\n";
}

class InClass {
    private function __clone() {}
    public function dup(): void {
        clone $this;
        echo "in-class ok\n";
    }
}
(new InClass())->dup();
--EXPECT--
Call to private C::__clone() from global scope
in-class ok
