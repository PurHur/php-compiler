<?php
/**
 * #32524 — hashtable vs hashtable ordered compare / spaceship / loose equal.
 * php-src: Zend/zend_operators.c zend_compare_arrays; Zend/zend_hash.c zend_hash_compare
 * Assoc arrays are TYPE_HASHTABLE; packed lists stay on the #32501 native path.
 */
echo ['a' => 1] <=> ['a' => 2], "\n";
echo ['a' => 1] < ['a' => 2] ? "t\n" : "f\n";
echo ['a' => 2] > ['a' => 1] ? "t\n" : "f\n";
echo ['a' => 1] <=> ['a' => 1], "\n";
echo (['a' => 1] == ['a' => 1]) ? "eq\n" : "neq\n";
echo (['a' => 1] == ['a' => 2]) ? "eq\n" : "neq\n";
echo (['b' => 1, 'a' => 2] == ['a' => 2, 'b' => 1]) ? "eq\n" : "neq\n";
echo ['a' => 1, 'b' => 2] <=> ['a' => 1], "\n";
