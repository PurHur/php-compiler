--TEST--
mbstring null $string operands coerce when non-strict (#18273, ext/mbstring/mbstring.c)
--FILE--
<?php
echo mb_strlen(null), "\n";
echo mb_substr(null, 0), "\n";
echo mb_strtolower(null), "\n";
?>
--EXPECT--
0

0
