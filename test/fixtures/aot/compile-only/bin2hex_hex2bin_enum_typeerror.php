<?php
declare(strict_types=1);
// Compile-only (#5734): bin2hex()/hex2bin() must lower enum-case TypeError guards for AOT.
enum B: int { case One = 1; }
try {
    bin2hex(B::One);
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
try {
    hex2bin(B::One);
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
