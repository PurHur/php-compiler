--TEST--
Language: trait method alias with visibility change — private alias callable internally (#9428, zend_traits.c)
--FILE--
<?php
trait T {
    public function m(): int {
        return 1;
    }
}
class C {
    use T {
        m as private p;
    }
    public function call(): int {
        return $this->p();
    }
}
var_dump((new C())->call());
try {
    (new C())->p();
    echo "fail\n";
} catch (Error $e) {
    echo $e->getMessage(), "\n";
}
?>
--EXPECT--
int(1)
Call to private method C::p() from global scope
