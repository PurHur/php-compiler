--TEST--
pack() numeric formats coerce null→0 on 8.4 (#21654, ext/standard/pack.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--RUNFILE--
issue_21654_pack_null.php
--EXPECT--
00
0000
4
00000000
pack OK
