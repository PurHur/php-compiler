--TEST--
stdlib strlen() — object operand TypeError, no __toString coercion (#10166, ext/standard/string.c)
--FILE--
<?php
declare(strict_types=1);

class C implements Stringable {
    public function __toString(): string { return 'hello'; }
}

try {
    strlen(new C());
    echo "uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
strlen(): Argument #1 ($string) must be of type string, C given
