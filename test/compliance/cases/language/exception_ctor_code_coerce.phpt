--TEST--
Language: Exception/Error/ErrorException::__construct() — Z_PARAM_LONG code coercion (#28797, Zend/zend_exceptions.c)
--FILE--
<?php
error_reporting(E_ALL);
set_error_handler(static function (int $no, string $str): bool {
    if (E_DEPRECATED === $no) {
        echo 'DEP:', $str, "\n";
        return true;
    }
    return false;
});

$e = new Exception('m', '42');
echo 'Exception str:', $e->getCode(), ':', $e->getMessage(), "\n";

$e = new Exception('m', 5.9);
echo 'Exception float:', $e->getCode(), "\n";

$e = new Exception('m', true);
echo 'Exception bool:', $e->getCode(), "\n";

$e = new Exception('m', null);
echo 'Exception null:', $e->getCode(), "\n";

$e = new Error('m', '9');
echo 'Error str:', $e->getCode(), "\n";

$e = new ErrorException('m', '7', E_USER_ERROR);
echo 'ErrorException str:', $e->getCode(), "\n";

try {
    new Exception('m', 'abc');
    echo "Exception nonnum: unreachable\n";
} catch (TypeError $e) {
    echo 'Exception nonnum:', $e->getMessage(), "\n";
}
?>
--EXPECT--
Exception str:42:m
DEP:Implicit conversion from float 5.9 to int loses precision
Exception float:5
Exception bool:1
DEP:Exception::__construct(): Passing null to parameter #2 ($code) of type int is deprecated
Exception null:0
Error str:9
ErrorException str:7
Exception nonnum:Exception::__construct(): Argument #2 ($code) must be of type int, string given
