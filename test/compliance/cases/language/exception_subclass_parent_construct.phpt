--TEST--
Language: user Exception subclass parent::__construct() invokes parent ctor (#6735, zend_exceptions.c)
--FILE--
<?php
class MyException extends Exception {
    public function __construct(string $message = '', int $code = 0, ?Throwable $previous = null) {
        parent::__construct($message, $code, $previous);
    }
}
echo (new MyException('hi'))->getMessage(), "\n";

class MyError extends Error {
    public function __construct(string $message = '', int $code = 0, ?Throwable $previous = null) {
        parent::__construct($message, $code, $previous);
    }
}
echo (new MyError('err'))->getMessage(), "\n";
--EXPECT--
hi
err
