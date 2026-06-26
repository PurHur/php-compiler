--TEST--
__destruct() throw during unset() dispatches to enclosing catch (#12070)
--FILE--
<?php
class A {
    public function __destruct() {
        throw new Exception('d');
    }
}
try {
    $a = new A();
    unset($a);
    echo "after\n";
} catch (Exception $e) {
    echo "caught\n";
}
--EXPECT--
caught
