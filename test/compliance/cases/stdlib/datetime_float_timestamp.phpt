--TEST--
stdlib date/gmdate/getdate/idate accept float timestamp without strict_types (#14807, ext/date/php_date.c)
--FILE--
<?php

echo gmdate('u', 1.23456789), "\n";
echo getdate(1.5)['seconds'], "\n";
echo date('Y', 1.5), "\n";
echo idate('s', 1.5), "\n";
--EXPECT--
000000
1
1970
1
