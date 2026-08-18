<?php
/**
 * #32431 — string⊙string bitwise is byte-wise (zend bitwise_*_function).
 * Leftover of #32407 (native long ⊙ numeric-string).
 * Avoid bin2hex(): user-script AOT of bin2hex("a") currently SIGSEGVs independently.
 */
echo ord('a' ^ 'b'), PHP_EOL;
var_dump('AB' & 'A');
var_dump('A' | 'BC');
echo ord('AB' ^ 'C'), PHP_EOL;
var_dump('' | 'x');
var_dump('xy' & '');
var_dump('7' & '3');
