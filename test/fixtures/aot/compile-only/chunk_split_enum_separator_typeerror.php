<?php
// Compile-only (#6032): chunk_split() separator must lower enum-case TypeError guards for AOT.
enum ES: string { case X = '-'; }
try {
    chunk_split('abc', 2, ES::X);
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
