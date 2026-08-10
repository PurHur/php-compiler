--TEST--
AOT: strstr() null $needle TypeError under strict_types (#29766, ext/standard/string.c)
--FILE--
<?php
declare(strict_types=1);

strstr('abc', null);
--EXPECT--
--EXPECT_EXIT--
255
