--TEST--
AOT: DateTime::modify(null) TypeError under strict_types (#29818, ext/date/php_date.c)
--FILE--
<?php
declare(strict_types=1);

(new DateTime('2020-01-01'))->modify(null);
--EXPECT--
--EXPECT_EXIT--
255
