<?php
declare(strict_types=1);

// Repro for #20529 — PDO::connect + Pdo\Sqlite
$p = PDO::connect('sqlite::memory:');
echo get_class($p), PHP_EOL;
echo $p->query('SELECT 1')->fetchColumn(), PHP_EOL;
