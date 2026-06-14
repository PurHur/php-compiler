<?php
enum E: string {
    case A = 'a';
    case B = 'b';
}

try {
    strnatcmp(E::A, E::B);
    echo "uncaught\n";
} catch (TypeError $e) {
    echo "TypeError\n";
}
