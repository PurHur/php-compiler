--TEST--
private property rejected from unrelated class method
--FILE--
<?php
class A {
    private string $x = 'hidden';
}

class B {
    public function probe(A $a): void {
        try {
            echo $a->x;
        } catch (Throwable $e) {
            echo get_class($e), "\n";
        }
    }
}

(new B())->probe(new A());
--EXPECT--
Error
