--TEST--
stdlib array_product() array: named parameter (#16463, ext/standard/array.c)
--FILE--
<?php
echo array_product(array: [2, 3, 4]), "\n";
echo array_product([2, 3, 4]), "\n";
--EXPECT--
24
24
