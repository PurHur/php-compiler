<?php
/**
 * Issue #21992 — ??= dim write auto-vivifies undefined/null container (zend_execute.c).
 */
$b['k'] ??= 'y';
echo $b['k'], "\n";
$c = null;
$c['k'] ??= 'y';
var_export($c);
echo "\n";
