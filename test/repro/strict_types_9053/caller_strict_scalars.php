<?php
declare(strict_types=1);
require __DIR__.'/callee_scalars.php';
foreach (['takesFloat', 'takesString', 'takesBool'] as $fn) {
    try {
        $fn('1');
        echo "$fn NO ERROR\n";
    } catch (Throwable $e) {
        echo get_class($e), " $fn\n";
    }
}
