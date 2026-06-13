--TEST--
AOT: array_sum()/array_product() scalar coercion (#4278)
--FILE--
<?php
declare(strict_types=1);
echo array_sum([1, 'x']), "\n";
echo array_product([1, 'x']), "\n";
echo array_sum([true, false]), "\n";
echo array_product([true, false]), "\n";
echo array_sum([null, 1]), "\n";
?>
--EXPECT--
1
0
1
0
1
