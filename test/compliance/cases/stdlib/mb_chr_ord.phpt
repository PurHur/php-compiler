--TEST--
stdlib mb_chr()/mb_ord() — codepoint conversion (php-src ext/mbstring/mbstring.c, #4559)
--FILE--
<?php
echo function_exists('mb_chr') ? "chr_exists\n" : "chr_missing\n";
echo function_exists('mb_ord') ? "ord_exists\n" : "ord_missing\n";
echo mb_chr(0x1F600, 'UTF-8'), "\n";
echo mb_ord('😀', 'UTF-8'), "\n";
echo mb_chr(65), "\n";
echo mb_ord('A'), "\n";
var_export(mb_chr(-1));
echo "\n";
var_export(mb_chr(0x110000));
echo "\n";
--EXPECT--
chr_exists
ord_exists
😀
128512
A
65
false
false
