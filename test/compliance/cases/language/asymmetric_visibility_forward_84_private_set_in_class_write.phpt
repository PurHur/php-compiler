--TEST--
Language: public private(set) in-class write + external deny wording (#23110, Zend/zend_object_handlers.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
class U {
    public private(set) int $n = 0;
    public function bump(): void {
        $this->n = $this->n + 1;
    }
}
$u = new U();
$u->bump();
echo $u->n, "\n";

class T {
    public private(set) string $x = "a";
    public protected(set) string $y = "b";
}
$t = new T();
try {
    $t->x = "z";
    echo "x_ok\n";
} catch (Error $e) {
    echo "x:", $e->getMessage(), "\n";
}
try {
    $t->y = "z";
    echo "y_ok\n";
} catch (Error $e) {
    echo "y:", $e->getMessage(), "\n";
}

class Child extends U {
    public function bad(): void {
        $this->n = 9;
    }
}
try {
    (new Child())->bad();
    echo "child_ok\n";
} catch (Error $e) {
    echo "child:", $e->getMessage(), "\n";
}
--EXPECT--
1
x:Cannot modify private(set) property T::$x from global scope
y:Cannot modify protected(set) property T::$y from global scope
child:Cannot modify private(set) property U::$n from child
