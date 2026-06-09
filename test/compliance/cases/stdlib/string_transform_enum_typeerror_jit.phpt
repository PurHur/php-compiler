--TEST--
stdlib str_repeat/wordwrap/str_replace/str_ireplace JIT — enum case TypeError (#5889, php-src-strict)
--FILE--
<?php
enum E: string { case A = 'a'; }
try {
    str_repeat(E::A, 1);
    echo "str_repeat uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
try {
    wordwrap(E::A);
    echo "wordwrap uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
try {
    str_replace('a', 'b', E::A);
    echo "str_replace uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
try {
    str_ireplace('a', 'b', E::A);
    echo "str_ireplace uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
echo str_repeat('x', 2), "\n";
--EXPECT--
str_repeat(): Argument #1 ($string) must be of type string, E given
wordwrap(): Argument #1 ($string) must be of type string, E given
str_replace(): Argument #3 ($subject) must be of type array|string, E given
str_ireplace(): Argument #3 ($subject) must be of type array|string, E given
xx
