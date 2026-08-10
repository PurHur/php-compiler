--TEST--
AOT: mb_chr()/mb_ord() null TypeError under strict_types (#29778, ext/mbstring/mbstring.c)
--FILE--
<?php
declare(strict_types=1);

mb_chr(null);
--EXPECT--
--EXPECT_EXIT--
255
