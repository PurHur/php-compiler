--TEST--
stdlib string compare builtins — enum case operands TypeError (#5733, ext/standard/string.c)
--FILE--
<?php
enum S: string { case X = 'a'; }
enum U { case Y; }

try {
    strncmp(S::X, 'b', 1);
    echo "uncaught strncmp\n";
} catch (TypeError $e) {
    echo 'strncmp: ', $e->getMessage(), "\n";
}
try {
    strcasecmp(S::X, 'b');
    echo "uncaught strcasecmp\n";
} catch (TypeError $e) {
    echo 'strcasecmp: ', $e->getMessage(), "\n";
}
try {
    strncasecmp(S::X, 'b', 1);
    echo "uncaught strncasecmp\n";
} catch (TypeError $e) {
    echo 'strncasecmp: ', $e->getMessage(), "\n";
}
try {
    strnatcmp(U::Y, 'b');
    echo "uncaught strnatcmp\n";
} catch (TypeError $e) {
    echo 'strnatcmp: ', $e->getMessage(), "\n";
}
try {
    strnatcasecmp(U::Y, 'b');
    echo "uncaught strnatcasecmp\n";
} catch (TypeError $e) {
    echo 'strnatcasecmp: ', $e->getMessage(), "\n";
}
--EXPECT--
strncmp: strncmp(): Argument #1 ($string1) must be of type string, S given
strcasecmp: strcasecmp(): Argument #1 ($string1) must be of type string, S given
strncasecmp: strncasecmp(): Argument #1 ($string1) must be of type string, S given
strnatcmp: strnatcmp(): Argument #1 ($string1) must be of type string, U given
strnatcasecmp: strnatcasecmp(): Argument #1 ($string1) must be of type string, U given
