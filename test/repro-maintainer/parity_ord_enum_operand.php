<?php
enum E: int { case A = 65; }
try {
    var_export(ord(E::A));
} catch (Throwable $e) {
    echo $e->getMessage(), "\n";
}
