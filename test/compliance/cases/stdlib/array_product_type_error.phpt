--TEST--
stdlib array_product() — empty array and non-numeric string contributes zero (#4262, #4278)
--FILE--
<?php
echo array_product([]), "\n";
echo array_product([1, 'x']), "\n";
echo array_product([1, 'notnum']), "\n";
--EXPECT--
1
0
0
