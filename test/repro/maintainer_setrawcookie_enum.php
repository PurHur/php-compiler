<?php
declare(strict_types=1);
enum E: string { case A = 'v'; }
try {
    setrawcookie(E::A, 'cookie');
    echo "uncaught name\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
enum V: string { case B = 'x'; }
try {
    setrawcookie('n', V::B);
    echo "uncaught value\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
