<?php
// Compile-only (#6032): count_chars()/chunk_split() must lower enum-case TypeError guards for AOT.
enum ES: string { case X = 'x'; }
enum EI: int { case A = 1; }
try {
    count_chars(ES::X);
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
try {
    chunk_split('abc', EI::A);
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
