<?php

declare(strict_types=1);

enum Ei: int
{
    case N = 5;
}

$c = Ei::N;

foreach (['abs', 'ceil', 'floor', 'round', 'sqrt'] as $fn) {
    try {
        $fn($c);
        echo $fn, " uncaught\n";
    } catch (TypeError $e) {
        echo $e->getMessage(), "\n";
    }
}
