--TEST--
true/false typed property TypeError includes class::$prop (issue #31108)
--FILE--
<?php
class TrueP {
    public true $x;
}
class FalseP {
    public false $y;
}
class IntP {
    public int $z;
}

$o = new TrueP();
try {
    $o->x = false;
    echo "true prop ok\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}

$o2 = new FalseP();
try {
    $o2->y = true;
    echo "false prop ok\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}

$o3 = new IntP();
try {
    $o3->z = [];
    echo "int prop ok\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
--EXPECT--
TypeError: Cannot assign bool to property TrueP::$x of type true
TypeError: Cannot assign bool to property FalseP::$y of type false
TypeError: Cannot assign array to property IntP::$z of type int
