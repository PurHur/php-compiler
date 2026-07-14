--TEST--
stdlib date_create(null) / DateTime(null) — null datetime coerces JIT on 8.4 profile (#18903, ext/date/php_date.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--JIT--
--FILE--
<?php
$dt = date_create(null);
echo 'date_create: ', ($dt instanceof DateTime) ? 'DateTime' : 'fail', "\n";

$dt2 = new DateTime(null);
echo 'DateTime: ', ($dt2 instanceof DateTime) ? 'DateTime' : 'fail', "\n";
--EXPECT--
date_create: DateTime
DateTime: DateTime
