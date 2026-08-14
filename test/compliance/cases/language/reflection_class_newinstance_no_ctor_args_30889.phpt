--TEST--
Language: ReflectionClass::newInstance/newInstanceArgs ReflectionException when args passed to ctor-less class (#30889)
--FILE--
<?php
$r = new ReflectionClass(stdClass::class);
try {
    $o = $r->newInstance(1);
    echo 'newInstance ok ', get_class($o), "\n";
} catch (Throwable $e) {
    echo 'newInstance ', get_class($e), ': ', $e->getMessage(), "\n";
}
try {
    $o = $r->newInstanceArgs([1]);
    echo 'newInstanceArgs ok ', get_class($o), "\n";
} catch (Throwable $e) {
    echo 'newInstanceArgs ', get_class($e), ': ', $e->getMessage(), "\n";
}
try {
    $o = $r->newInstance();
    echo 'empty newInstance ok ', get_class($o), "\n";
} catch (Throwable $e) {
    echo 'empty newInstance ', get_class($e), ': ', $e->getMessage(), "\n";
}
try {
    $o = $r->newInstanceArgs([]);
    echo 'empty newInstanceArgs ok ', get_class($o), "\n";
} catch (Throwable $e) {
    echo 'empty newInstanceArgs ', get_class($e), ': ', $e->getMessage(), "\n";
}

class WithCtor {
    public function __construct(public int $x) {}
}
$rw = new ReflectionClass(WithCtor::class);
try {
    $o = $rw->newInstance(7);
    echo 'WithCtor ok ', get_class($o), ' x=', $o->x, "\n";
} catch (Throwable $e) {
    echo 'WithCtor ', get_class($e), ': ', $e->getMessage(), "\n";
}
--EXPECT--
newInstance ReflectionException: Class stdClass does not have a constructor, so you cannot pass any constructor arguments
newInstanceArgs ReflectionException: Class stdClass does not have a constructor, so you cannot pass any constructor arguments
empty newInstance ok stdClass
empty newInstanceArgs ok stdClass
WithCtor ok WithCtor x=7
