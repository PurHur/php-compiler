--TEST--
stdlib strlen() JIT — object operand TypeError (#10166)
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
