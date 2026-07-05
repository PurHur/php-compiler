--TEST--
stdlib array_sum() array: named parameter (#16463, ext/standard/array.c)
--FILE--
<?php
echo array_sum(array: [1, 2, 3]), "\n";
echo array_sum([1, 2, 3]), "\n";
--EXPECT--
6
6
