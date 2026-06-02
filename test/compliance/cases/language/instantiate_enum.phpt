--TEST--
Language: new on enum throws Error (#4631, zend_compile.c / zend_objects.c)
--FILE--
<?php
enum E { case A; }
enum F: int { case B = 1; }

try {
    new E();
} catch (Error $e) {
    echo 'static: ', get_class($e), ': ', $e->getMessage(), "\n";
} catch (LogicException $e) {
    echo 'static: LogicException: ', $e->getMessage(), "\n";
}

$class = F::class;
try {
    new $class();
} catch (Error $e) {
    echo 'dynamic: ', get_class($e), ': ', $e->getMessage(), "\n";
} catch (LogicException $e) {
    echo 'dynamic: LogicException: ', $e->getMessage(), "\n";
}
?>
--EXPECT--
static: Error: Cannot instantiate enum E
dynamic: Error: Cannot instantiate enum F
