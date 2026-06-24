--TEST--
stdlib string builtins — TypeError names user class, not generic object (#11227, ext/standard/string.c)
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

try {
    substr(new C(), 0);
    echo "uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
strlen(): Argument #1 ($string) must be of type string, C given
substr(): Argument #1 ($string) must be of type string, C given
