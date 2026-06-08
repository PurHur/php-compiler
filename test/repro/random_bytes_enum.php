<?php
enum E: int { case A = 1; }
try {
    random_bytes(E::A);
    echo "accepted\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
