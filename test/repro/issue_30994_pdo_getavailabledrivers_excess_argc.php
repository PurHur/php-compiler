<?php
/**
 * Repro #30994 — PDO::getAvailableDrivers / pdo_drivers excess argc → ArgumentCountError.
 * php-src: ext/pdo/pdo.c zim_PDO_getAvailableDrivers / PHP_FUNCTION(pdo_drivers)
 */
try {
    var_export(PDO::getAvailableDrivers(1));
    echo "\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
try {
    var_export(pdo_drivers(1));
    echo "\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
$ok = PDO::getAvailableDrivers();
$ok2 = pdo_drivers();
echo 'ok=', (is_array($ok) && is_array($ok2)) ? '1' : '0', "\n";
