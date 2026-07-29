--TEST--
Language: new on trait throws Error (#24893, zend_API.c / zend_execute.c)
--FILE--
<?php
trait T {}

try {
    new T();
    echo "static: ACCEPTED\n";
} catch (Error $e) {
    echo 'static: ', get_class($e), ': ', $e->getMessage(), "\n";
} catch (LogicException $e) {
    echo 'static: LogicException: ', $e->getMessage(), "\n";
}

$class = T::class;
try {
    new $class();
    echo "dynamic: ACCEPTED\n";
} catch (Error $e) {
    echo 'dynamic: ', get_class($e), ': ', $e->getMessage(), "\n";
} catch (LogicException $e) {
    echo 'dynamic: LogicException: ', $e->getMessage(), "\n";
}
?>
--EXPECT--
static: Error: Cannot instantiate trait T
dynamic: Error: Cannot instantiate trait T
