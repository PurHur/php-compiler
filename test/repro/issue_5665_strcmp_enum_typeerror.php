<?php
// Issue #5665 — strcmp() on enum case must TypeError (ext/standard/string.c)
enum E { case A; }
try {
    strcmp(E::A, '');
    echo "uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
