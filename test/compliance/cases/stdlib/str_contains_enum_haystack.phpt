--TEST--
stdlib str_contains()/str_starts_with()/str_ends_with() — enum haystack/needle TypeError (#6033, ext/standard/string.c)
--FILE--
<?php
declare(strict_types=1);

enum Es: string {
    case A = 'ab';
}

try {
    str_contains(Es::A, 'a');
    echo "str_contains: uncaught\n";
} catch (TypeError $e) {
    echo "str_contains: ", $e->getMessage(), "\n";
}
try {
    str_contains('ab', Es::A);
    echo "str_contains needle: uncaught\n";
} catch (TypeError $e) {
    echo "str_contains needle: ", $e->getMessage(), "\n";
}
try {
    str_starts_with(Es::A, 'a');
    echo "str_starts_with: uncaught\n";
} catch (TypeError $e) {
    echo "str_starts_with: ", $e->getMessage(), "\n";
}
try {
    str_starts_with('ab', Es::A);
    echo "str_starts_with needle: uncaught\n";
} catch (TypeError $e) {
    echo "str_starts_with needle: ", $e->getMessage(), "\n";
}
try {
    str_ends_with(Es::A, 'b');
    echo "str_ends_with: uncaught\n";
} catch (TypeError $e) {
    echo "str_ends_with: ", $e->getMessage(), "\n";
}
try {
    str_ends_with('ab', Es::A);
    echo "str_ends_with needle: uncaught\n";
} catch (TypeError $e) {
    echo "str_ends_with needle: ", $e->getMessage(), "\n";
}
?>
--EXPECT--
str_contains: str_contains(): Argument #1 ($haystack) must be of type string, Es given
str_contains needle: str_contains(): Argument #2 ($needle) must be of type string, Es given
str_starts_with: str_starts_with(): Argument #1 ($haystack) must be of type string, Es given
str_starts_with needle: str_starts_with(): Argument #2 ($needle) must be of type string, Es given
str_ends_with: str_ends_with(): Argument #1 ($haystack) must be of type string, Es given
str_ends_with needle: str_ends_with(): Argument #2 ($needle) must be of type string, Es given
