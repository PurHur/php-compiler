<?php
enum E: int { case A = 1; }
foreach ([
    'microtime' => fn () => microtime(E::A),
    'hrtime' => fn () => hrtime(E::A),
    'gettimeofday' => fn () => gettimeofday(E::A),
    'time_nanosleep' => fn () => time_nanosleep(E::A, 0),
] as $name => $fn) {
    try {
        $fn();
    } catch (Throwable $e) {
        echo "$name: ", $e::class, "\n";
    }
}
