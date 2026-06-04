--TEST--
stdlib hash_equals() — enum case operands TypeError (#5760, ext/standard/hash.c)
--FILE--
<?php
declare(strict_types=1);

enum E: string { case A = 'x'; }

foreach ([
    ['arg1', fn () => hash_equals(E::A, 'x')],
    ['arg2', fn () => hash_equals('x', E::A)],
] as [$label, $call]) {
    try {
        $call();
        echo "$label: uncaught\n";
    } catch (TypeError $e) {
        echo "$label: ", $e->getMessage(), "\n";
    }
}
?>
--EXPECT--
arg1: hash_equals(): Argument #1 ($known_string) must be of type string, E given
arg2: hash_equals(): Argument #2 ($user_string) must be of type string, E given
