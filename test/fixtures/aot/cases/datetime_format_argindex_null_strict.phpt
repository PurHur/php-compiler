--TEST--
AOT: DateTime::format(null) TypeError cites Argument #1 (#29819, ext/date/php_date.c)
--FILE--
<?php
declare(strict_types=1);

(new DateTime('2020-01-01'))->format(null);
--EXPECT--
--EXPECT_EXIT--
255
