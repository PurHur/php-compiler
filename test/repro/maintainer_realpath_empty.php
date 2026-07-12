<?php

declare(strict_types=1);

/**
 * Issue #17820 — php-src-strict: realpath('') resolves to cwd, not false.
 *
 * @see Zend/zend_virtual_cwd.c virtual_realpath — "realpath("") returns CWD"
 */

$emptyIsFalse = realpath('') === false;
$dot = realpath('.');
$emptyPath = realpath('');

echo 'empty_is_false=', var_export($emptyIsFalse, true), "\n";
echo 'empty_eq_dot=', var_export($emptyPath === $dot, true), "\n";
