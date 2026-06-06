<?php
declare(strict_types=1);
/**
 * Maintainer repro for #6581 — session_id() enum case must TypeError (php-src Z_PARAM_STR).
 */

enum E: string {
    case A = 'sessid';
}

try {
    session_id(E::A);
    echo "uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}

echo session_id(null), "\n";
echo session_id('abc123'), "\n";
echo session_id(), "\n";
