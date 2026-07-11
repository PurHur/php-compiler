--TEST--
Reflection: ReflectionClass::getProperty() — ReflectionException + ArgumentCountError (#4468, ext/reflection/php_reflection.c)
--FILE--
<?php
class C {
    public int $x = 1;
}

$rc = new ReflectionClass(C::class);

try {
    $rc->getProperty('nope');
} catch (ReflectionException $e) {
    echo $e->getMessage(), "\n";
}

try {
    $rc->getProperty();
} catch (ArgumentCountError $e) {
    echo $e->getMessage(), "\n";
}

try {
    $rc->getProperty('x', 'extra');
} catch (ArgumentCountError $e) {
    echo $e->getMessage(), "\n";
}

try {
    $rc->getProperty([]);
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
Property C::$nope does not exist
ReflectionClass::getProperty() expects exactly 1 argument, 0 given
ReflectionClass::getProperty() expects exactly 1 argument, 2 given
ReflectionClass::getProperty(): Argument #1 ($name) must be of type string, array given
