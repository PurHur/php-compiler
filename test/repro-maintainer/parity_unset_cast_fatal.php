<?php
/**
 * Maintainer repro for #5324 — (unset) cast must be compile-time fatal on PHP 8+.
 *
 * Zend PHP 8.x: Fatal error: The (unset) cast is no longer supported
 */

declare(strict_types=1);

$a = 1;
$b = (unset) $a;
var_export(isset($a));
echo "\n";
var_export($b);
echo "\n";
