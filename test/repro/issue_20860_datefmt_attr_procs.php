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
