--TEST--
protected property rejected from global scope
--FILE--
<?php
class B {
    protected string $p = 'secret';
}

$b = new B();
try {
    echo $b->p;
} catch (Throwable $e) {
    echo get_class($e), "\n";
}
--EXPECT--
Error
