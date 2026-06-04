<?php
enum E: int { case A = 65; }
try {
    chr(E::A);
    echo "uncaught\n";
} catch (Throwable $e) {
    echo $e->getMessage(), "\n";
}
