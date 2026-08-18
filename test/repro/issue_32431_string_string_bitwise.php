<?php
/**
 * #32431 — string⊙string bitwise is byte-wise (zend bitwise_*_function).
 * Leftover of #32407 (native long ⊙ numeric-string).
 */
echo bin2hex('a' ^ 'b'), PHP_EOL;
var_dump('AB' & 'A');
var_dump('A' | 'BC');
echo bin2hex('AB' ^ 'C'), PHP_EOL;
var_dump('' | 'x');
var_dump('xy' & '');
var_dump('7' & '3');
