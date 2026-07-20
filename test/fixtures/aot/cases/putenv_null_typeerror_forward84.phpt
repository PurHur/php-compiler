--TEST--
AOT putenv(null) — soft-null DEP then ValueError on 8.4 (#21312, reverts #21004)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
error_reporting(E_ALL & ~E_DEPRECATED);
putenv(null);
?>
--EXPECT--
--EXPECT_EXIT--
134
