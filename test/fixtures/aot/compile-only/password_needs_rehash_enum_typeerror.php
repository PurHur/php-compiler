<?php
declare(strict_types=1);
// Compile-only (#6242): password_needs_rehash() must lower enum-case TypeError guards for AOT.
enum E: string { case A = 'secret'; }
try {
    password_needs_rehash(E::A, PASSWORD_BCRYPT);
    echo "uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
