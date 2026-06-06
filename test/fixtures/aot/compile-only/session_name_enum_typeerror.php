<?php
declare(strict_types=1);
// Compile-only (#6536): session_name() must lower enum-case TypeError guards for AOT.
enum E: string { case A = 'PHPSESSID'; }
try {
    session_name(E::A);
    echo "uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
