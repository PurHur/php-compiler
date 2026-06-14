--TEST--
AOT: array_sum() — TypeError on non-array first argument (#4504)
--FILE--
<?php
array_sum('x');
--EXPECT--
--EXPECT_EXIT--
255
