--TEST--
DateTime/DateTimeImmutable format constants defined() + :: fetch JIT (#22271, ext/date/php_date.c)
--FILE--
<?php
echo defined('DateTime::ATOM') ? '1' : '0', "\n";
echo defined('DateTimeImmutable::ATOM') ? '1' : '0', "\n";
echo defined('DateTime::RFC3339_EXTENDED') ? '1' : '0', "\n";
echo defined('DateTimeImmutable::RFC7231') ? '1' : '0', "\n";
echo DateTime::ATOM, "\n";
echo DateTimeImmutable::W3C, "\n";
--EXPECT--
1
1
1
1
Y-m-d\TH:i:sP
Y-m-d\TH:i:sP
