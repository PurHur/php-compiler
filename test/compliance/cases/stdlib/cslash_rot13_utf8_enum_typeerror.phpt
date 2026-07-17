--TEST--
stdlib utf8_encode/str_rot13/addcslashes/stripcslashes — backed enum case TypeError (#5956, #8846, ext/standard)
--FILE--
<?php
enum E: string { case A = 'x'; }

$tests = [
    ['utf8_encode', static fn () => utf8_encode(E::A)],
    ['str_rot13', static fn () => str_rot13(E::A)],
    ['addcslashes', static fn () => addcslashes(E::A, 'a')],
    ['stripcslashes', static fn () => stripcslashes(E::A)],
];

foreach ($tests as [$name, $fn]) {
    try {
        $fn();
        echo $name, ": uncaught\n";
    } catch (TypeError $e) {
        echo $name, ': ', $e->getMessage(), "\n";
    }
}
--EXPECT--
utf8_encode: utf8_encode(): Argument #1 ($string) must be of type string, E given
str_rot13: str_rot13(): Argument #1 ($string) must be of type string, E given
addcslashes: addcslashes(): Argument #1 ($string) must be of type string, E given
stripcslashes: stripcslashes(): Argument #1 ($string) must be of type string, E given
