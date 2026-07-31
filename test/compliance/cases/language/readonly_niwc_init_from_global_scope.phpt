--TEST--
Language: readonly property NIWC + global first-init Error (zend_readonly.c, #25745)
--FILE--
<?php
class R {
    public readonly int $x;
    public function __construct(int $x) { $this->x = $x; }
    public function init(int $x): void { $this->x = $x; }
}
$o = (new ReflectionClass(R::class))->newInstanceWithoutConstructor();
try {
    $o->x = 1;
    echo "SET_OK\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
try {
    echo $o->x, "\n";
} catch (Throwable $e) {
    echo 'read=', get_class($e), ':', $e->getMessage(), "\n";
}
$o2 = (new ReflectionClass(R::class))->newInstanceWithoutConstructor();
try {
    $o2->init(2);
    echo 'method_ok:', $o2->x, "\n";
} catch (Throwable $e) {
    echo 'method=', get_class($e), ':', $e->getMessage(), "\n";
}
echo 'ctor:', (new R(3))->x, "\n";
--EXPECT--
Error:Cannot initialize readonly property R::$x from global scope
read=Error:Typed property R::$x must not be accessed before initialization
method_ok:2
ctor:3
