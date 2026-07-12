--TEST--
AOT: substr_count() null needle aborts — typed guard (#18312, ext/standard/string.c)
--FILE--
<?php
substr_count('haystack', null);
--EXPECT--
--EXPECT_EXIT--
134
