--TEST--
date strtotime after date_default_timezone_set UTC (#27550, ext/date/php_date.c)
--FILE--
<?php
date_default_timezone_set('UTC');
echo strtotime('2020-01-15'), "\n";
?>
--EXPECT--
1579046400
