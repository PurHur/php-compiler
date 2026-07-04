--TEST--
stdlib number_format() negative $decimals ignored like 0 on Zend 8.2 (issue #15917)
--FILE--
<?php
echo number_format(1.5, -1), "\n";
echo number_format(1234.5678, -1), "\n";
--EXPECT--
2
1,235
