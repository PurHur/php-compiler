--TEST--
AOT: array_key_exists() — TypeError on non-array second argument (#4722)
--FILE--
<?php
array_key_exists('key', 'not-array');
--EXPECT--
--EXPECT_EXIT--
134
