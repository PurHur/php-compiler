--TEST--
Typed property rejects incompatible scalar writes (issue #169)
--FILE--
<?php
class C {
    public string $x = '';
}
$c = new C();
try {
    $c->x = [];
    echo "array ok\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
--EXPECT--
TypeError: Cannot assign array to property C::$x of type string
