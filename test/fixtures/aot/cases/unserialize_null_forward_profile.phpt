--TEST--
AOT: unserialize(null) — TypeError on 8.4 forward profile (#18840, ext/standard/var.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
unserialize(null);
--EXPECT--
--EXPECT_EXIT--
134
