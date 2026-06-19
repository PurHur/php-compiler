<?php

declare(strict_types=1);

try {
    $dt = DateTime::createFromFormat('Y-m-d', '2024-06-05');
    echo $dt->format('Y-m-d'), "\n";
    echo get_class($dt), "\n";
} catch (Throwable $t) {
    echo get_class($t), ': ', $t->getMessage(), "\n";
}

$bad = DateTime::createFromFormat('Y', 'notadate');
var_export($bad);
echo "\n";
