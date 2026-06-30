--TEST--
stdlib timezone_transitions_get() default range — prompt TZif list (#11069, ext/date/php_date.c)
--FILE--
<?php
declare(strict_types=1);
$tz = new DateTimeZone('UTC');
$trans = timezone_transitions_get($tz);
echo is_array($trans) ? count($trans) : 0, "\n";
$berlin = new DateTimeZone('Europe/Berlin');
$berlinTrans = timezone_transitions_get($berlin);
echo is_array($berlinTrans) ? count($berlinTrans) : 0, "\n";
--EXPECT--
1
144
