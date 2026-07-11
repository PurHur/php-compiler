--TEST--
AOT: array_combine() inline literal count mismatch throws ValueError (#16080)
--FILE--
<?php
array_combine(['a'], [1, 2]);
echo "no throw\n";
--EXPECT--
--EXPECT_EXIT--
134
