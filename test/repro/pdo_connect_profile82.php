<?php
// Repro #22600 — PDO::connect withheld on PROFILE=8.2 (Zend 8.2 parity)
echo 'method_exists=', method_exists(PDO::class, 'connect') ? '1' : '0', "\n";
try {
    $p = PDO::connect('sqlite::memory:');
    echo 'connect=', get_class($p), "\n";
} catch (Throwable $e) {
    echo 'connect=', get_class($e), ': ', $e->getMessage(), "\n";
}
