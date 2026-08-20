--TEST--
AOT: printf/sprintf %s float/int coerce — no libc %s with double (#33010)
--FILE--
<?php
printf("%s\n", 1.5);
echo sprintf("%s\n", 1.5);
printf("%s\n", 42);
printf("%.1f\n", 1.5);
printf("%s\n", PHP_INT_MAX + 1.0);
--EXPECT--
1.5
1.5
42
1.5
9.2233720368548E+18
