--TEST--
AOT: strchr() null $haystack TypeError under strict_types (#29783, ext/standard/string.c)
--FILE--
<?php
declare(strict_types=1);

strchr(null, 'a');
--EXPECT--
--EXPECT_EXIT--
255
