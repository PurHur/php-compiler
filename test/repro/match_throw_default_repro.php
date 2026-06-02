<?php
declare(strict_types=1);
$x = 0;
try {
    $r = match ($x) {
        1 => 'a',
        default => throw new Exception('d'),
    };
    var_export($r);
} catch (Exception $e) {
    echo 'caught: ', $e->getMessage(), "\n";
}
