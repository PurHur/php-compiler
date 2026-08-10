<?php

/**
 * #29560 — empty($arr[$float]) emits Implicit conversion Deprecated once (Zend parity).
 *
 * php-src: Zend/zend_execute.c zend_isset_dim / empty; Zend/zend_operators.c precision-loss DEP.
 */
error_reporting(E_ALL);

function capture(int $errno, string $message): bool
{
    echo ($errno === E_DEPRECATED ? 'D:' : 'W:'), $message, "\n";

    return true;
}
set_error_handler('capture');

$a = [1 => 'x'];
var_export(isset($a[1.5]));
echo "\n";
var_export(empty($a[1.5]));
echo "\n";
