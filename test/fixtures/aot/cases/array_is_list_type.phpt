--TEST--
AOT: array_is_list() — TypeError on non-array (#4753, ext/standard/array.c)
--FILE--
<?php
array_is_list('x');
--EXPECT--
--EXPECT_EXIT--
134
