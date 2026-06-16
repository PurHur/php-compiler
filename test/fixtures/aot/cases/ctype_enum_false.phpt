--TEST--
AOT: ctype_*() enum case operands return false (#8962, ext/standard/ctype.c)
--FILE--
<?php
enum E: string { case A = 'abc'; case B = '123'; }

echo (int) ctype_alpha(E::A), "\n";
echo (int) ctype_digit(E::B), "\n";
echo (int) ctype_alnum(E::A), "\n";
echo (int) ctype_alpha('abc'), "\n";
--EXPECT--
0
0
0
1
--EXPECT_EXIT--
0
