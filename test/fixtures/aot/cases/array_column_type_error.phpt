--TEST--
AOT: array_column() — TypeError on non-array first argument (#4504)
--FILE--
<?php
array_column(null, 'k');
--EXPECT--
--EXPECT_EXIT--
134
