--TEST--
Static property read visibility — private/protected/parent:: (issue #6785, Zend zend_object_handlers.c)
--FILE--
<?php
class Base {
    private static int $priv = 1;
    protected static int $prot = 2;
    private static int $x = 9;
    public static int $pub = 3;
}

class Derived extends Base {
    public static function parentPrivate(): void {
        try {
            echo parent::$x;
            echo "BUG\n";
        } catch (Throwable $e) {
            echo get_class($e), "\n";
        }
    }
}

echo Base::$pub, "\n";
try {
    echo Base::$priv;
    echo "BUG\n";
} catch (Throwable $e) {
    echo get_class($e), "\n";
}
Derived::parentPrivate();
--EXPECT--
3
Error
Error
