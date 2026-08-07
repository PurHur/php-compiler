--TEST--
Language: Exception/Error/ErrorException::__construct() — non-Throwable $previous TypeError (#28798, Zend/zend_exceptions.c)
--FILE--
<?php
declare(strict_types=1);

try {
    throw new Exception('m', 0, new stdClass);
} catch (TypeError $e) {
    echo 'Exception: ', $e->getMessage(), "\n";
}

try {
    throw new Error('m', 0, new stdClass);
} catch (TypeError $e) {
    echo 'Error: ', $e->getMessage(), "\n";
}

try {
    throw new ErrorException('m', 0, E_ERROR, __FILE__, __LINE__, new stdClass);
} catch (TypeError $e) {
    echo 'ErrorException: ', $e->getMessage(), "\n";
}

try {
    throw new Exception('m', 0, 123);
} catch (TypeError $e) {
    echo 'int: ', $e->getMessage(), "\n";
}

$prev = new Exception('p');
try {
    throw new Exception('m', 0, $prev);
} catch (Exception $e) {
    echo 'chain: ', get_class($e->getPrevious()), "\n";
}
?>
--EXPECT--
Exception: Exception::__construct(): Argument #3 ($previous) must be of type ?Throwable, stdClass given
Error: Error::__construct(): Argument #3 ($previous) must be of type ?Throwable, stdClass given
ErrorException: ErrorException::__construct(): Argument #6 ($previous) must be of type ?Throwable, stdClass given
int: Exception::__construct(): Argument #3 ($previous) must be of type ?Throwable, int given
chain: Exception
