--TEST--
stdlib number_format() >14 decimal places matches Zend (ext/standard/number_format.c, #18525)
--FILE--
<?php

echo number_format(1.1, 20), "\n";
echo number_format(1.5, -1), "\n";

--EXPECT--
1.10000000000000008882
2
