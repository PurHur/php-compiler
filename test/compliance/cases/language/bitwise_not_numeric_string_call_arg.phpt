--TEST--
Language: unary ~ on numeric string in call argument (zend_operators.c, #10537)
--FILE--
<?php
echo bin2hex(~"5"), "\n";
echo bin2hex(~"0"), "\n";
echo bin2hex(~"255"), "\n";
--EXPECT--
ca
cf
cdcaca
