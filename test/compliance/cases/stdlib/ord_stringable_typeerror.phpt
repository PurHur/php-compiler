--TEST--
stdlib ord() rejects Stringable object without __toString coercion (#10882, ext/standard/string.c)
--FILE--
<?php
class C {
    public function __toString(): string {
        return 'A';
    }
}
try {
    ord(new C());
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
ord(): Argument #1 ($character) must be of type string, C given
