<?php
declare(strict_types=1);
// Compile-only (#6581): session_id() must lower enum-case TypeError guards for AOT.
enum E: string { case A = 'sessid'; }
try {
    session_id(E::A);
    echo "uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
