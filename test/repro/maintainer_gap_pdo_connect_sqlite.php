<?php
declare(strict_types=1);

// Repro for #20529 — PDO::connect + Pdo\Sqlite
echo method_exists(PDO::class, 'connect') ? 'connect:Y' : 'connect:N', PHP_EOL;
echo class_exists('Pdo\\Sqlite') ? 'Sqlite:Y' : 'Sqlite:N', PHP_EOL;
if (method_exists(PDO::class, 'connect')) {
    $p = PDO::connect('sqlite::memory:');
    echo get_class($p), PHP_EOL;
    echo $p->query('SELECT 1')->fetchColumn(), PHP_EOL;
}
