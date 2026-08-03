<?php

declare(strict_types=1);

/**
 * Repro #27308 — AOT DateTimeZone::getOffset() must match Zend/VM/JIT (-14400).
 */
$z = new DateTimeZone('America/New_York');
$d = new DateTime('2024-07-01 12:00:00', $z);
echo $z->getOffset($d), "\n";
echo timezone_offset_get($z, $d), "\n";
