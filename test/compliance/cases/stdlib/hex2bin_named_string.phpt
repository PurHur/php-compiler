--TEST--
stdlib hex2bin() string: named parameter (#16317, ext/standard/string.c)
--FILE--
<?php
echo bin2hex(hex2bin(string: '6162')), "\n";
--EXPECT--
6162
