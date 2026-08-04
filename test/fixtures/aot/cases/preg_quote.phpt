--TEST--
AOT: preg_quote() escapes regex metacharacters (default helper-runtime, #27564)
--FILE--
<?php
echo preg_quote('a.b'), "\n";
echo preg_quote('x|y', '|'), "\n";
echo preg_quote('a.b*c', '/'), "\n";
--EXPECT--
a\.b
x\|y
a\.b\*c
