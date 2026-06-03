--TEST--
duplicate trait class constants with identical values merge (issue #4651, Zend zend_inheritance.c)
--FILE--
<?php
trait T1 {
    public const N = 42;
}
trait T2 {
    public const N = 42;
}
class C {
    use T1, T2;
}
echo C::N, "\n";
--EXPECT--
42
