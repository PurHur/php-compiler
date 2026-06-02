--TEST--
AOT: array_key_last() — TypeError on non-array
--FILE--
<?php
array_key_last(null);
--EXPECT--
--EXPECT_EXIT--
134
