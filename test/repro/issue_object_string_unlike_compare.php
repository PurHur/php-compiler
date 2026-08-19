<?php
/**
 * #32515 — object vs string ordered compare / spaceship / loose equal.
 * php-src: Zend/zend_operators.c compare_function / zend_compare
 * Object vs string without __toString: object is greater; == is false.
 */
class C32515 {}
echo (new stdClass() > "a") ? "gt\n" : "ngt\n";
echo (new stdClass() <=> "a"), "\n";
echo ("a" > new stdClass()) ? "rgt\n" : "rngt\n";
echo (new stdClass() == "a") ? "eq\n" : "neq\n";
echo (new stdClass() != "a") ? "ne\n" : "nne\n";
echo (new C32515() >= "z") ? "cge\n" : "ncge\n";
echo ("z" <=> new stdClass()), "\n";
