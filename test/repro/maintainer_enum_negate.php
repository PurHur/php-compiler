<?php

declare(strict_types=1);

enum E: int
{
    case A = 1;
}

try {
    var_export(-E::A);
    echo "no throw\n";
} catch (TypeError $e) {
    echo 'TypeError:', $e->getMessage(), "\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
