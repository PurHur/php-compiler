--TEST--
AOT: mb_split() null $pattern TypeError under strict_types (#29811, ext/mbstring/php_mbregex.c)
--FILE--
<?php
declare(strict_types=1);

mb_split(null, 'a');
--EXPECT--
--EXPECT_EXIT--
255
