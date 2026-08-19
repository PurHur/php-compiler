<?php
/**
 * #32503 — object/array vs scalar ordered compare / spaceship.
 * php-src: Zend/zend_operators.c compare_function / zend_compare
 * Object vs int: E_NOTICE + legacy 1. Array vs non-array: array is greater.
 */
class C32503 {}
echo (new stdClass() > 1) ? "gt\n" : "ngt\n";
echo (new stdClass() <=> 1), "\n";
echo (1 > new stdClass()) ? "rgt\n" : "rngt\n";
echo ([1] > 1) ? "agt\n" : "angt\n";
echo ([1] <=> 1), "\n";
echo (new C32503() >= 1) ? "cge\n" : "ncge\n";
