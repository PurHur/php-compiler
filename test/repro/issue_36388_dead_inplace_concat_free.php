<?php
/**
 * Thin AOT: dead in-place typed `$out .=` must free after unset (#36388).
 * php-src: Zend/zend_operators.c ZEND_ASSIGN_CONCAT / zend_string_extend.
 */
function build(): string
{
    $out = '';
    $out .= 'a';
    $out = $out.'b';
    $out .= 'c';

    return $out;
}

$u0 = memory_get_usage(false);
$s = build();
$u1 = memory_get_usage(false);
unset($s);
$u2 = memory_get_usage(false);
$left = $u2 - $u0;
echo 'dead_inplace_concat delta=', $left, ' ', ($left === 0 ? 'ok' : 'LEAK'), "\n";
