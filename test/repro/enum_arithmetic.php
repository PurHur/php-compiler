<?php
enum E: int { case A = 1; case B = 2; }
try {
    $x = E::A + E::A;
    var_export($x);
} catch (Throwable $e) {
    echo get_class($e) . ': ' . $e->getMessage();
}
echo "\n";
