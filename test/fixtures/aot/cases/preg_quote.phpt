--TEST--
AOT: preg_quote() escapes regex metacharacters
--FILE--
<?php
echo preg_quote('a.b'), "\n";
echo preg_quote('x|y', '|'), "\n";
--EXPECT--
a\.b
x\|y
