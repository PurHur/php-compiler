--TEST--
trait private/protected const — self:: access inside trait methods (issue #9187, Zend/zend_traits.c)
--FILE--
<?php
trait T {
    private const X = 99;
    protected const Y = 42;
    public function getPrivate(): int {
        return self::X;
    }
    public function getProtected(): int {
        return self::Y;
    }
}
class C { use T; }
$c = new C();
var_export($c->getPrivate());
echo "\n";
var_export($c->getProtected());
echo "\n";
try {
    var_export(T::X);
    echo "BUG\n";
} catch (Error $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
99
42
Cannot access trait constant T::X directly
