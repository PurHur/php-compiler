--TEST--
Language: __PROPERTY__ outside property hook — runtime Error on default profile (#18900, Zend/zend_constants.c)
--SKIPIF--
<?php
die('skip — compiler VM/JIT compliance via VMTest/JITTest, not Zend CLI');
?>
--FILE--
<?php
class C {
    public function m(): void {
        echo __PROPERTY__;
    }
}
try {
    (new C)->m();
} catch (Throwable $e) {
    echo get_class($e), "\n";
    echo $e->getMessage(), "\n";
}
--EXPECT--
Error
Undefined constant "__PROPERTY__"
