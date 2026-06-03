--TEST--
AOT: implode() — TypeError for non-array haystack (#4906, ext/standard/string.c)
--FILE--
<?php
implode(',', 'x');
--EXPECT--
--EXPECT_EXIT--
134
