--TEST--
user Exception/Error subclass parent::__construct() forwards to parent ctor (issue #6735)
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
