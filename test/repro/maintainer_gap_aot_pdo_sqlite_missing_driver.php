<?php

declare(strict_types=1);

// Maintainer repro: #27619 — AOT PDO missing-driver honesty (sqlite absent).
// Thin AOT cannot var_export() arrays (#26855); use scalar-safe printing.
$drivers = PDO::getAvailableDrivers();
echo 'is_array=', is_array($drivers) ? '1' : '0', "\n";
echo 'count=', is_array($drivers) ? (string) count($drivers) : 'n/a', "\n";
echo 'drivers=', is_array($drivers) ? implode(',', $drivers) : 'NULL', "\n";
try {
    $pdo = new PDO('sqlite::memory:');
    echo "connected\n";
    echo 'quote=', var_export($pdo->quote("O'Reilly"), true), "\n";
} catch (Throwable $e) {
    // Thin AOT get_class()/instanceof on emitCatchableClassError objects is unreliable;
    // message matches Zend ("could not find driver").
    echo 'PDOException: ', $e->getMessage(), "\n";
}
