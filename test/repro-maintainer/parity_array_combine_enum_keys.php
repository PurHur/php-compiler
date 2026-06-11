<?php
enum E: int { case A = 1; case B = 2; }
try {
    $k = array_combine([E::A, E::B], [10, 20]);
    var_export($k);
} catch (Throwable $e) {
    echo get_class($e) . ': ' . $e->getMessage() . "\n";
}
