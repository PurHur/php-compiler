--TEST--
AOT: mb_convert_encoding() null $string TypeError under strict_types (#29777, ext/mbstring/mbstring.c)
--FILE--
<?php
declare(strict_types=1);

mb_convert_encoding(null, 'UTF-8');
--EXPECT--
--EXPECT_EXIT--
255
