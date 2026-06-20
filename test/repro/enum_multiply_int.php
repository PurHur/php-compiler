<?php
enum E: int { case A = 1; }
try {
    $x = E::A * 2;
    var_export($x);
} catch (Throwable $e) {
    echo get_class($e) . ': ' . $e->getMessage();
}
echo "\n";
