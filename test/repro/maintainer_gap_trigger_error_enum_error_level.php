<?php

declare(strict_types=1);

enum E: int { case A = 1; }

try {
    trigger_error('msg', E::A);
    echo "uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
