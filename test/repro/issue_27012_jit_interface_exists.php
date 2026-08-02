<?php

/**
 * #27012 — JIT interface declaration + interface_exists must match Zend (not missing
 * phpc_basetozval_result). User interfaces take the full MCJIT path (requiresVmLowering=false),
 * which NestedJITs WeakRef and can lower hexdec → basetozval.
 *
 * php-src: Zend/zend_builtin_functions.c — PHP_FUNCTION(interface_exists)
 */
interface I {}
var_export(interface_exists('I'));
echo "\n";
