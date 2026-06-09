<?php
enum E: string { case A = 'a'; }
$checks = [
    'str_repeat' => static fn () => str_repeat(E::A, 1),
    'wordwrap' => static fn () => wordwrap(E::A),
    'str_replace' => static fn () => str_replace('a', 'b', E::A),
    'str_ireplace' => static fn () => str_ireplace('a', 'b', E::A),
];
foreach ($checks as $name => $fn) {
    try {
        $fn();
        echo "{$name}: uncaught\n";
    } catch (TypeError $e) {
        echo $e->getMessage(), "\n";
    }
}
