--TEST--
datefmt attribute procedurals locale/datetype/lenient/calendar (#20860)
--FILE--
<?php
$f = datefmt_create('en_US', IntlDateFormatter::SHORT, IntlDateFormatter::NONE, 'UTC', IntlDateFormatter::GREGORIAN);
echo 'locale=', datefmt_get_locale($f), "\n";
echo 'datetype=', datefmt_get_datetype($f), ' oop=', $f->getDateType(), "\n";
echo 'timetype=', datefmt_get_timetype($f), ' oop=', $f->getTimeType(), "\n";
echo 'lenient=', datefmt_is_lenient($f) ? 'yes' : 'no', "\n";
echo 'set_lenient=', datefmt_set_lenient($f, false) ? 'yes' : 'no', "\n";
echo 'lenient2=', datefmt_is_lenient($f) ? 'yes' : 'no', "\n";
echo 'cal=', datefmt_get_calendar($f), ' oop=', $f->getCalendar(), "\n";
echo 'set_cal=', datefmt_set_calendar($f, IntlDateFormatter::TRADITIONAL) ? 'yes' : 'no', "\n";
echo 'cal2=', datefmt_get_calendar($f), "\n";
echo 'tzid=', datefmt_get_timezone_id($f), "\n";
$co = datefmt_get_calendar_object($f);
echo 'calobj=', ($co instanceof IntlCalendar) ? 'yes' : 'no', "\n";
foreach ([
  'datefmt_get_locale','datefmt_get_datetype','datefmt_get_timetype',
  'datefmt_is_lenient','datefmt_set_lenient','datefmt_get_calendar',
  'datefmt_set_calendar','datefmt_get_timezone_id','datefmt_get_calendar_object',
] as $fn) {
  echo "$fn=", function_exists($fn) ? 'yes' : 'no', "\n";
}
?>
--EXPECT--
locale=en_US
datetype=3 oop=3
timetype=-1 oop=-1
lenient=yes
set_lenient=yes
lenient2=no
cal=1 oop=1
set_cal=yes
cal2=0
tzid=UTC
calobj=yes
datefmt_get_locale=yes
datefmt_get_datetype=yes
datefmt_get_timetype=yes
datefmt_is_lenient=yes
datefmt_set_lenient=yes
datefmt_get_calendar=yes
datefmt_set_calendar=yes
datefmt_get_timezone_id=yes
datefmt_get_calendar_object=yes
