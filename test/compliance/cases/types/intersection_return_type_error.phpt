--TEST--
Intersection return type rejects incompatible values (#6499)
--FILE--
<?php
interface A {}
interface B {}
class C implements A, B {}

function named(): A&B {
    return new stdClass();
}

class D {
    public function m(): A&B {
        return new stdClass();
    }
}

foreach (['named', 'method'] as $which) {
    try {
        if ('named' === $which) {
            named();
        } else {
            (new D())->m();
        }
    } catch (Throwable $e) {
        echo $which, ': ', get_class($e), "\n";
    }
}
?>
--EXPECT--
named: TypeError
method: TypeError
