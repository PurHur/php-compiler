--TEST--
AOT: hex2bin() $strict on valid input (issue #4966)
--FILE--
<?php
echo bin2hex(hex2bin('4142')), "\n";
echo bin2hex(hex2bin('4142', true)), "\n";
--EXPECT--
4142
4142
