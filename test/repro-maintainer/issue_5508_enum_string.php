<?php
enum E: string { case A = 'a'; }
foreach ([
    'cast' => fn () => (string) E::A,
    'strval' => fn () => strval(E::A),
    'concat' => fn () => 'x' . E::A,
] as $label => $fn) {
    try {
        echo $label, ': ', $fn(), "\n";
    } catch (Throwable $e) {
        echo $label, ': ', get_class($e), ': ', $e->getMessage(), "\n";
    }
}
