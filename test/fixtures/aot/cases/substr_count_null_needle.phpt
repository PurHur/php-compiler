--TEST--
AOT: substr_count() null needle — soft-null DEP then Uncaught ValueError empty (#18347/#29421, ext/standard/string.c)
--FILE--
<?php
// Uncaught: AOT try/catch does not yet catch rejectEmpty ValueError (peer str_increment #26264).
substr_count('haystack', null);
--EXPECT--
--EXPECT_EXIT--
255
