<?php
/**
 * #32538 — runtime hashtable <=> after assignment / return / dim write (leftover of #32524).
 * php-src: Zend/zend_operators.c zend_compare_arrays
 */
$x = ['a' => 1];
$y = ['a' => 2];
echo $x <=> $y, "\n";

function a(): array
{
    return ['a' => 1];
}

function b(): array
{
    return ['a' => 2];
}

echo a() <=> b(), "\n";

$m = ['a' => 1];
$m['c'] = 3;
echo $m <=> ['a' => 1], "\n";

echo ['a' => 1] <=> ['a' => 2], "\n";
