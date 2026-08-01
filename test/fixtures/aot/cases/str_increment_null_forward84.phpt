--TEST--
AOT: str_increment(null) — soft-null then Uncaught ValueError empty on 8.4 (#26264, re-#24179)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
// Uncaught: AOT try/catch does not yet catch rejectEmpty ValueError (same as setcookie #21233 fixture).
str_increment(null);
--EXPECT--
--EXPECT_EXIT--
255
