<?php
// Repro for #20413 — ATTR_EMULATE_PREPARES on sqlite (php-src-strict IM001)
declare(strict_types=1);

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
echo 'set_emulate=', var_export($pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, true), true), "\n";
try {
    echo 'get=', var_export($pdo->getAttribute(PDO::ATTR_EMULATE_PREPARES), true), "\n";
} catch (Throwable $e) {
    echo 'get_ERR=', $e->getMessage(), "\n";
}
