--TEST--
AOT: array_sum(null) TypeError aborts — Zend typed array (#21926/#21916, ext/standard/array.c)
--FILE--
<?php
array_sum(null);
--EXPECT--
--EXPECT_EXIT--
134
