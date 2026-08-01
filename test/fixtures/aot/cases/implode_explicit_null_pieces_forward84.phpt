--TEST--
AOT: implode([1,2], null) — separator TypeError abort on PROFILE=8.4 (#26277)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
$a = [1, 2];
$n = null;
implode($a, $n);
--EXPECT--
--EXPECT_EXIT--
134
