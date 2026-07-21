--TEST--
AOT: array_keys(null) TypeError aborts — Zend typed array (#21915, ext/standard/array.c)
--FILE--
<?php
array_keys(null);
--EXPECT--
--EXPECT_EXIT--
134
