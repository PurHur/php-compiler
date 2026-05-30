--TEST--
trait class constants — C::N and T::N fetch (issue #3431, Zend zend_traits.c)
--FILE--
<?php
trait T {
    public const N = 42;
}
class C {
    use T;
}
echo C::N, ' ', T::N, "\n";
--EXPECT--
42 42
