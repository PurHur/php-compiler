<?php
/**
 * Maintainer repro for #4983 — unset() on nullsafe ?-> must compile-time fatal (php-src RFC).
 *
 * Zend PHP 8.x: Fatal error: Can't use nullsafe operator in write context
 */
$o = null;
unset($o?->x);
echo "ok\n";
