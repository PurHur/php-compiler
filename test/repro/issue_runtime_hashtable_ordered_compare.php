<?php
/**
 * #32536 — assigned / returned hashtable ordered compare (leftover of #32524/#32528).
 * php-src: Zend/zend_operators.c zend_compare_arrays; Zend/zend_hash.c zend_hash_compare
 */
$a = ['a' => 1];
$b = ['a' => 2];
$c = ['a' => 1];
echo $a <=> $b, "\n";
echo $a < $b ? "t\n" : "f\n";
echo $a <=> $c, "\n";
function ha(): array
{
    return ['k' => 1];
}
function hb(): array
{
    return ['k' => 2];
}
echo ha() <=> hb(), "\n";
echo ['a' => 1] <=> ['a' => 2], "\n";
$e = [];
$n = null;
echo $e > $n ? "t\n" : "f\n";
