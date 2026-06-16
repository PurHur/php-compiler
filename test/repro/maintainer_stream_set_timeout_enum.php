<?php
declare(strict_types=1);

enum E: int {
    case A = 1;
}

$fp = fopen('php://memory', 'r+');

try {
    stream_set_timeout(E::A, 1);
    echo "stream-uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}

try {
    stream_set_timeout($fp, E::A);
    echo "seconds-uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}

try {
    stream_set_timeout($fp, 1, E::A);
    echo "microseconds-uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}

fclose($fp);
