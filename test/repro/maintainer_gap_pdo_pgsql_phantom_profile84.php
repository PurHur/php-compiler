<?php
/**
 * #28158 — Pdo\Pgsql must not phantom under PROFILE≥8.4 when host lacks pdo_pgsql.
 *
 * Expected (host without pdo_pgsql):
 *   ext=0
 *   class=0
 */
declare(strict_types=1);

echo 'ext=', extension_loaded('pdo_pgsql') ? '1' : '0', "\n";
echo 'class=', class_exists('Pdo\\Pgsql') ? '1' : '0', "\n";
