--TEST--
stdlib strspn()/strcspn() — TypeError for enum case operands (ext/standard/string.c)
--FILE--
<?php
enum E: string { case A = 'abc'; }
foreach ([
    static fn () => strspn(E::A, 'a'),
    static fn () => strspn('a', E::A),
    static fn () => strcspn(E::A, 'a'),
    static fn () => strcspn('a', E::A),
] as $i => $fn) {
    try {
        $fn();
        echo "test{$i}: uncaught\n";
    } catch (TypeError $e) {
        echo "test{$i}: TypeError\n";
    }
}
--EXPECT--
test0: TypeError
test1: TypeError
test2: TypeError
test3: TypeError
