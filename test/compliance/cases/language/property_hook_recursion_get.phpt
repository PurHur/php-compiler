--TEST--
Language: get hook reading same virtual property throws Error (#10005, zend_object_handlers.c)
--FILE--
<?php
class C {
    public string $x {
        get { return $this->x; }
    }
}
$c = new C();
try {
    echo "before\n";
    $v = $c->x;
    echo "val={$v}\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
echo "after\n";
--EXPECT--
before
Error: Must not read from virtual property C::$x
after
