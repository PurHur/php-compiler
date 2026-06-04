--TEST--
Language: uninitialized typed property read throws Error not Exception (#5458)
--FILE--
<?php
class C {
    public int $x;
}
$c = new C();
try {
    var_dump($c->x);
} catch (Error $e) {
    echo 'Error: ', $e->getMessage(), "\n";
} catch (Exception $e) {
    echo 'Exception: ', $e->getMessage(), "\n";
}
try {
    var_dump($c->x);
} catch (Throwable $e) {
    echo get_class($e), "\n";
}
--EXPECT--
Error: Typed property C::$x must not be accessed before initialization
Error
