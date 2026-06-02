--TEST--
AOT: array_product() — empty packed list returns 1 (#4262)
--FILE--
<?php
echo array_product([]), "\n";
--EXPECT--
1
