--TEST--
stdlib strnatcmp()/strnatcasecmp() — both enum case operands TypeError (#5933, ext/standard/string.c, php-src-strict)
--FILE--
<?php
enum E: string { case A = 'a'; case B = 'b'; }
enum U { case X; case Y; }

try {
    strnatcmp(E::A, E::B);
    echo "uncaught strnatcmp backed\n";
} catch (TypeError $e) {
    echo 'strnatcmp backed: ', $e->getMessage(), "\n";
}
try {
    strnatcasecmp(E::A, E::B);
    echo "uncaught strnatcasecmp backed\n";
} catch (TypeError $e) {
    echo 'strnatcasecmp backed: ', $e->getMessage(), "\n";
}
try {
    strnatcmp(U::X, U::Y);
    echo "uncaught strnatcmp unit\n";
} catch (TypeError $e) {
    echo 'strnatcmp unit: ', $e->getMessage(), "\n";
}
--EXPECT--
strnatcmp backed: strnatcmp(): Argument #1 ($string1) must be of type string, E given
strnatcasecmp backed: strnatcasecmp(): Argument #1 ($string1) must be of type string, E given
strnatcmp unit: strnatcmp(): Argument #1 ($string1) must be of type string, U given
