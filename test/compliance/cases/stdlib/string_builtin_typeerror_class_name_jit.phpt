--TEST--
stdlib string builtins JIT — TypeError names user class (#11227)
--JIT--
--FILE--
<?php
declare(strict_types=1);

class C {}

try {
    strlen(new C());
    echo "uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
strlen(): Argument #1 ($string) must be of type string, C given
