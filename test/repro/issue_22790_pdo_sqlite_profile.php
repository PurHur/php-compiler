<?php
declare(strict_types=1);

/**
 * #22790 — Pdo\Sqlite must not exist on default/8.2 profile (Zend 8.2).
 * Avoid method_exists(PDO::class, …) here: JIT verify bug under PROFILE=8.2.
 */
echo 'PROFILE=', getenv('PHP_COMPILER_PROFILE') ?: '(default)', PHP_EOL;
echo 'Pdo\\Sqlite=', class_exists('Pdo\\Sqlite') ? 'yes' : 'no', PHP_EOL;
