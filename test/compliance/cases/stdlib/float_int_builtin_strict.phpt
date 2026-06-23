--TEST--
stdlib strict_types float int builtin args throw TypeError (#10468)
--FILE--
<?php
declare(strict_types=1);

try {
    str_repeat('a', 1.5);
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
try {
    substr('hello', 1.5, 2.5);
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
try {
    str_pad('a', 5.5);
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
try {
    array_slice([1, 2, 3], 1.5, 1.5);
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
?>
--EXPECT--
TypeError: str_repeat(): Argument #2 ($times) must be of type int, float given
TypeError: substr(): Argument #2 ($offset) must be of type int, float given
TypeError: str_pad(): Argument #2 ($length) must be of type int, float given
TypeError: array_slice(): Argument #2 ($offset) must be of type int, float given
