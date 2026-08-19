<?php
/**
 * #32539 — runtime boxed assoc <=> / < > must match Zend (not compile-time fold).
 * php-src: Zend/zend_operators.c zend_compare_arrays; Zend/zend_hash.c zend_hash_compare
 */
$a = ['a' => 1];
$b = ['a' => 2];
$c = ['a' => 1];
echo $a <=> $b, "\n";
echo $a < $b ? "t\n" : "f\n";
echo $a <=> $c, "\n";
echo ['a' => 1] <=> ['a' => 2], "\n";
