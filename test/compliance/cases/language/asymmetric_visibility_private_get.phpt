--TEST--
PHP 8.4 asymmetric visibility: private(get) read guard (#5059, zend_object_handlers.c)
--FILE--
<?php
class Box {
    private(get) string $secret = 'hidden';
}

$b = new Box();
try {
    echo $b->secret, "\n";
} catch (Error $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
$b->secret = 'ok';
echo "set ok\n";
try {
    echo $b->secret, "\n";
} catch (Error $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
--EXPECT--
Error: Cannot access private(get) property Box::$secret from global scope
set ok
Error: Cannot access private(get) property Box::$secret from global scope
