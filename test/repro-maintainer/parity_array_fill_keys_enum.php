<?php
enum E: int { case A = 1; case B = 2; }
try {
    $k = array_fill_keys([E::A, E::B], 'x');
    var_dump($k);
} catch (Throwable $e) {
    echo get_class($e) . ': ' . $e->getMessage() . "\n";
}
