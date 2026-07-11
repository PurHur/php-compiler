<?php
declare(strict_types=1);

enum E: string { case A = 'abc'; }

foreach (['strstr', 'stristr', 'strchr', 'strrchr', 'strrpos'] as $fn) {
    try {
        $fn(E::A, 'a');
        echo "$fn: no error\n";
    } catch (Throwable $e) {
        echo "$fn: ", get_class($e), "\n";
    }
}
