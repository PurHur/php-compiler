--TEST--
Language: Exception/Error::__construct() — strict_types rejects non-string $message (#18189, Zend/zend_exceptions.c)
--FILE--
<?php
declare(strict_types=1);

class PlainToString {
    public function __toString(): string
    {
        return 'plain';
    }
}

class StringableMsg implements Stringable {
    public function __toString(): string
    {
        return 'boom';
    }
}

class ThrowingToString implements Stringable {
    public function __toString(): string
    {
        throw new Exception('inner');
    }
}

enum Color: string { case Red = 'red'; }

foreach (
    [
        'Exception Stringable' => [Exception::class, new StringableMsg()],
        'Error Stringable' => [Error::class, new StringableMsg()],
        'RuntimeException int' => [RuntimeException::class, 123],
        'ValueError int' => [ValueError::class, 456],
        'TypeError plain object' => [TypeError::class, new PlainToString()],
        'enum' => [Exception::class, Color::Red],
        'array' => [Exception::class, []],
        'throwing __toString' => [Exception::class, new ThrowingToString()],
    ] as $label => [$class, $message]
) {
    try {
        new $class($message);
        echo $label, ":constructed\n";
    } catch (TypeError $e) {
        echo $label, ':', $e->getMessage(), "\n";
    }
}
?>
--EXPECT--
Exception Stringable:Exception::__construct(): Argument #1 ($message) must be of type string, StringableMsg given
Error Stringable:Error::__construct(): Argument #1 ($message) must be of type string, StringableMsg given
RuntimeException int:RuntimeException::__construct(): Argument #1 ($message) must be of type string, int given
ValueError int:ValueError::__construct(): Argument #1 ($message) must be of type string, int given
TypeError plain object:TypeError::__construct(): Argument #1 ($message) must be of type string, PlainToString given
enum:Exception::__construct(): Argument #1 ($message) must be of type string, Color given
array:Exception::__construct(): Argument #1 ($message) must be of type string, array given
throwing __toString:Exception::__construct(): Argument #1 ($message) must be of type string, ThrowingToString given
