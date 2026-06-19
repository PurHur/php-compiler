<?php
enum E: int { case A = 1; }
try {
    throw E::A;
} catch (Error $e) {
    echo $e->getMessage(), "\n";
}
