<?php
/**
 * Issue #14133 — echo concat drops LHS when RHS is function_exists() ternary.
 * Zend: strlen:y  VM (bug): y
 */
$fn = 'strlen';
echo $fn . ':' . (function_exists($fn) ? 'y' : 'n');
