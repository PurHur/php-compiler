<?php
/**
 * str_pad native r1 matches Zend (#36388).
 * php-src: ext/standard/string.c PHP_FUNCTION(str_pad).
 */
echo str_pad('hi', 5, '-'), "\n";
echo str_pad('hi', 5, '-', STR_PAD_LEFT), "\n";
echo str_pad('hi', 6, '-', STR_PAD_BOTH), "\n";
echo str_pad('x'.'y', 8, 'ab'), "\n";
echo str_pad('already', 3, '.'), "\n";
