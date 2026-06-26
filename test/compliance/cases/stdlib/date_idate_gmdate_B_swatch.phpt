--TEST--
stdlib date()/idate()/gmdate() format B — Swatch beats (issue #12224, ext/date/php_date.c)
--FILE--
<?php
declare(strict_types=1);
$t = 1710000000;
echo date('B', $t), "\n";
echo (string) idate('B', $t), "\n";
echo gmdate('B', $t), "\n";
echo date('B', 0), "\n";
--EXPECT--
708
708
708
041
