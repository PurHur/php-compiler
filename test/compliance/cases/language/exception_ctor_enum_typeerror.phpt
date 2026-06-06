--TEST--
Language: Exception/Error::__construct() — enum case message TypeError (#7156, Zend/zend_exceptions.c)
--FILE--
<?php
declare(strict_types=1);

enum Color: string { case Red = 'red'; }

try {
    throw new Exception(Color::Red);
} catch (TypeError $e) {
    echo 'Exception: ', $e->getMessage(), "\n";
}

try {
    throw new Error(Color::Red);
} catch (TypeError $e) {
    echo 'Error: ', $e->getMessage(), "\n";
}
?>
--EXPECT--
Exception: Exception::__construct(): Argument #1 ($message) must be of type string, Color given
Error: Error::__construct(): Argument #1 ($message) must be of type string, Color given
