<?php
// Issue #6032 — chunk_split() separator enum case must TypeError (ext/standard/string.c)
enum ES: string { case X = '-'; }
try {
    chunk_split('abc', 2, ES::X);
    echo "uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
