<?php
/**
 * Issue #17744 — json_encode() on backed enum case must encode backing scalar.
 */
enum E: string {
    case A = 'a';
}

echo json_encode(E::A), "\n";
echo json_encode([E::A]), "\n";
echo json_encode(E::A, JSON_THROW_ON_ERROR), "\n";
