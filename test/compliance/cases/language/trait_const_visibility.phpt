--TEST--
Trait-imported private/protected class constants — visibility on composing class (issue #6664, Zend zend_constants.c)
--FILE--
<?php
trait T {
    private const X = 1;
    protected const Y = 2;
    public const Z = 3;
}
class C { use T; }

echo C::Z, "\n";
try {
    echo C::X;
    echo "BUG: private readable\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
try {
    echo C::Y;
    echo "BUG: protected readable\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
--EXPECT--
3
Error: Cannot access private constant C::X
Error: Cannot access protected constant C::Y
