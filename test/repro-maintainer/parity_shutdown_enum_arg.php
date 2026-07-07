<?php

declare(strict_types=1);

enum E: int
{
    case A = 1;
}

register_shutdown_function(
    function (E $e): void {
        var_dump($e);
    },
    E::A
);
echo "ok\n";
