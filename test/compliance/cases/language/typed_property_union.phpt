--TEST--
Union typed property accepts int|string and rejects array (issue #169)
--FILE--
<?php
class C {
    public int|string $id = 0;
}
$c = new C();
$c->id = 'ok';
echo "str ok\n";
try {
    $c->id = [];
    echo "array ok\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
--EXPECT--
str ok
TypeError: Cannot assign array to property C::$id of type int|string
