<?php
declare(strict_types=1);

enum E: string { case A = 'a,b'; }

try {
    str_getcsv(E::A);
    echo "uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
