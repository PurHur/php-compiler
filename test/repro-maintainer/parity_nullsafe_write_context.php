<?php
/**
 * Maintainer repro for #5323 — nullsafe ?-> in write context must compile-time fatal.
 *
 * Zend PHP 8.x: Fatal error: Can't use nullsafe operator in write context
 */

declare(strict_types=1);

$obj = null;
$obj?->prop = 1;
var_export($obj?->prop);
echo "\n";

$a = null;
$a?->b ??= 5;
var_export($a?->b);
echo "\n";
