--TEST--
AOT: array_key_first() — TypeError on non-array
--FILE--
<?php
array_key_first(null);
--EXPECT--
--EXPECT_EXIT--
134
