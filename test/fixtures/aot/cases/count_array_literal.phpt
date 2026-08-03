--TEST--
AOT: count() on array literal after Countable dispatch (#27294 / re-#26793)
--FILE--
<?php
echo count([1, 2, 3]), "\n";
echo count([]), "\n";
--EXPECT--
3
0
--EXPECT_EXIT--
0
