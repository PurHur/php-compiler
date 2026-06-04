<?php

declare(strict_types=1);

enum E
{
    case A;
}

$x = E::A;
try {
    $x++;
} catch (TypeError $e) {
    echo 'TypeError:', $e->getMessage(), "\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
try {
    --$x;
} catch (TypeError $e) {
    echo 'TypeError:', $e->getMessage(), "\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
