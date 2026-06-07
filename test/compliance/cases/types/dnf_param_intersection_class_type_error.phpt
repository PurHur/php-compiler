--TEST--
DNF parameter type (I1&I2)|C rejects incompatible values (#4956)
--FILE--
<?php
interface I1 {}
interface I2 {}
class C implements I1, I2 {}

class DnfParam {
    public function m((I1&I2)|C $x): void {}
}

try {
    (new DnfParam())->m([]);
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
?>
--EXPECT--
TypeError: Argument must be of type (I1&I2)|C, array given
