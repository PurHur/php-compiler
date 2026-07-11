<?php
declare(strict_types=1);
/**
 * Maintainer repro for #18026 — nullsafe ?-> on scalar base is silent in Zend.
 */
echo (1)?->foo ?? 'nullsafe', "\n";
$obj = new stdClass();
echo $obj?->missing ?? 'ns', "\n";
