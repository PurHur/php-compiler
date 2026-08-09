--TEST--
AOT round() null $mode — soft-null then abort ValueError on PHP 8.4 (#29384)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
// Uncaught: AOT try/catch does not yet catch round-mode ValueError (peer substr_count #29421).
// Exit 134 (SIGABRT) after soft-null proves ValueError path, not TypeError-silent success.
round(1.5, 0, null);
--EXPECT--
--EXPECT_EXIT--
134
