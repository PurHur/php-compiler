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
            echo "BUG: parent private\n";
        } catch (Throwable $e) {
            echo get_class($e), ': ', $e->getMessage(), "\n";
        }
    }
}

echo Base::$pub, "\n";
try {
    echo Base::$priv;
    echo "BUG: global private\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
Derived::parentPrivate();
