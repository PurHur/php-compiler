<?php

declare(strict_types=1);

// Maintainer repro: #24523 — pdo_sqlite phantom on default profile (Zend without pdo_sqlite).
echo 'ext=', extension_loaded('pdo_sqlite') ? '1' : '0', "\n";
echo 'drivers=', implode(',', PDO::getAvailableDrivers()), "\n";
try {
    new PDO('sqlite::memory:');
    echo "open=ok\n";
} catch (Throwable $e) {
    echo 'open=', get_class($e), ':', $e->getMessage(), "\n";
}
