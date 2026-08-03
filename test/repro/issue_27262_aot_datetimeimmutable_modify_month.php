<?php

declare(strict_types=1);

/**
 * Repro #27262 — AOT DateTimeImmutable::modify('+1 month') must match Zend/VM/JIT.
 */
$di = new DateTimeImmutable('2024-01-01');
echo $di->modify('+1 month')->format('Y-m-d'), "\n";
$dt = new DateTime('2024-01-31');
echo $dt->modify('+1 month')->format('Y-m-d'), "\n";
echo $di->modify('+1 year')->format('Y-m-d'), "\n";
