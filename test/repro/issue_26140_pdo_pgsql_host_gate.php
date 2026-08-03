<?php
// Repro for #26140 — pdo_pgsql host gate + getAvailableDrivers + DSN acceptance
declare(strict_types=1);

echo 'ext_pdo_pgsql=', extension_loaded('pdo_pgsql') ? '1' : '0', "\n";
echo 'drivers=', implode(',', PDO::getAvailableDrivers()), "\n";
try {
    new PDO('pgsql:host=127.0.0.1;dbname=none', 'u', 'p');
    echo "open=ok\n";
} catch (Throwable $e) {
    echo str_contains($e->getMessage(), 'could not find driver') ? "open=no_driver\n" : "open=connect_err\n";
}
