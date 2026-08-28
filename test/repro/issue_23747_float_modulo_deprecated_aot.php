<?php

declare(strict_types=1);

/**
 * AOT: float % int emits E_DEPRECATED on precision loss (re-#23747).
 * php-src: Zend/zend_operators.c mod_function()
 */
error_reporting(E_ALL);

echo 'file=', var_export(5.5 % 2, true), "\n";

$fn = static function (): void {
    echo 'closure=', var_export(5.5 % 2, true), "\n";
};
$fn();
