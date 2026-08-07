<?php
/**
 * #27332 — Pdo\Mysql must not phantom under PROFILE≥8.4 when host lacks pdo_mysql.
 *
 * Expected (host without pdo_mysql):
 *   ext=0
 *   class=0
 */
declare(strict_types=1);

echo 'ext=', extension_loaded('pdo_mysql') ? '1' : '0', "\n";
echo 'class=', class_exists('Pdo\\Mysql') ? '1' : '0', "\n";
