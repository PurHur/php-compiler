--TEST--
trait class constants — C::N fetch via using class (issue #3431, Zend zend_traits.c)
--FILE--
<?php
trait T {
    public const N = 42;
}
class C {
    use T;
}
echo C::N, "\n";
--EXPECT--
42
