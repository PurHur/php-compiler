--TEST--
Reflection*::__construct too-few args throw ArgumentCountError like Zend (#22739)
--FILE--
<?php
enum E { case A; }
try {
    new ReflectionEnumUnitCase(E::A);
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
try {
    new ReflectionEnumUnitCase();
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
try {
    new ReflectionFunction();
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
try {
    new ReflectionClass();
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
try {
    new ReflectionProperty('C');
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
try {
    new ReflectionMethod();
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
--EXPECT--
ArgumentCountError:ReflectionEnumUnitCase::__construct() expects exactly 2 arguments, 1 given
ArgumentCountError:ReflectionEnumUnitCase::__construct() expects exactly 2 arguments, 0 given
ArgumentCountError:ReflectionFunction::__construct() expects exactly 1 argument, 0 given
ArgumentCountError:ReflectionClass::__construct() expects exactly 1 argument, 0 given
ArgumentCountError:ReflectionProperty::__construct() expects exactly 2 arguments, 1 given
ArgumentCountError:ReflectionMethod::__construct() expects at least 1 argument, 0 given
