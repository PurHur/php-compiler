--TEST--
stdlib number_format() JIT
--FILE--
<?php
echo number_format(1234.5, 2), "\n";
echo number_format(1000), "\n";
echo number_format(1234.567, 2, '.', ' '), "\n";
--EXPECT--
1,234.50
1,000
1 234.57
