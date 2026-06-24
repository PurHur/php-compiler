--TEST--
stdlib str_contains()/str_starts_with()/str_ends_with() — object operand TypeError (#11273, ext/standard/string.c)
--FILE--
<?php
declare(strict_types=1);

class C implements Stringable {
    public function __toString(): string { return 'obj'; }
}

foreach (['str_contains', 'str_starts_with', 'str_ends_with'] as $fn) {
    try {
        $fn(new C(), 'obj');
        echo "$fn: uncaught\n";
    } catch (TypeError $e) {
        echo "$fn: ", $e->getMessage(), "\n";
    }
}
--EXPECT--
str_contains: str_contains(): Argument #1 ($haystack) must be of type string, C given
str_starts_with: str_starts_with(): Argument #1 ($haystack) must be of type string, C given
str_ends_with: str_ends_with(): Argument #1 ($haystack) must be of type string, C given
