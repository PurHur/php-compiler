--TEST--
AOT: printf/sprintf("%s", float|int) stringify — not libc %s + double (#33010)
--FILE--
<?php
printf("%s\n", 1.5);
echo sprintf("%s\n", 1.5);
printf("%s\n", 7);
echo sprintf("%s\n", PHP_INT_MAX + 1.0);
printf("%.1f\n", 1.5);
--EXPECT--
1.5
1.5
7
9.2233720368548E+18
1.5
