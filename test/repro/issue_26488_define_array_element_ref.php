<?php
/**
 * #26488 — define() array element assign-by-ref must compile-fatal (re-#5409).
 *
 * Zend: Fatal error: Cannot use temporary expression in write context
 */
define('A', [1]);
$a = &A[0];
$a = 2;
var_dump(A);
