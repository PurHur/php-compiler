--TEST--
stdlib number_format() numeric string coercion (issue #3596)
--FILE--
<?php
echo number_format("1234.567", 2), "\n";
echo number_format("1e3"), "\n";
echo number_format("1234.567", 2, '.', ' '), "\n";
--EXPECT--
1,234.57
1,000
1 234.57
