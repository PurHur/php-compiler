--TEST--
AOT: number_format() null $decimals TypeError under strict_types (#29764, ext/standard/number_format.c)
--FILE--
<?php
declare(strict_types=1);

number_format(1.5, null);
--EXPECT--
--EXPECT_EXIT--
255
