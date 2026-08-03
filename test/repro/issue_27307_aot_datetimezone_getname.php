<?php

declare(strict_types=1);

/**
 * Repro #27307 — AOT DateTimeZone::getName() must match Zend/VM/JIT ('UTC').
 *
 * Avoid var_export(): thin AOT segfaults on var_export(string) independently of getName.
 */
$z = new DateTimeZone('UTC');
echo $z->getName(), "\n";
echo timezone_name_get($z), "\n";
