<?php

declare(strict_types=1);

/**
 * Repro #27309 — AOT DateTime::diff()->days must match Zend/VM/JIT (9).
 */
$a = new DateTime('2024-01-01');
$b = new DateTime('2024-01-10');
echo $a->diff($b)->days, "\n";
