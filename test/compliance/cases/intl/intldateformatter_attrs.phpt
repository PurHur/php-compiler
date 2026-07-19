--TEST--
IntlDateFormatter setPattern/getLocale/getDateType/isLenient/calendar/tz id (#20850)
--FILE--
<?php
$f = IntlDateFormatter::create(
    'en_US',
    IntlDateFormatter::SHORT,
    IntlDateFormatter::NONE,
    'UTC',
    IntlDateFormatter::GREGORIAN
);
echo 'locale=', $f->getLocale(), "\n";
echo 'dateType=', $f->getDateType(), "\n";
echo 'timeType=', $f->getTimeType(), "\n";
echo 'calendar=', $f->getCalendar(), "\n";
echo 'tzid=', $f->getTimeZoneId(), "\n";
echo 'lenient=', (int) $f->isLenient(), "\n";
$f->setLenient(false);
echo 'lenient2=', (int) $f->isLenient(), "\n";
echo 'style_pattern=', $f->getPattern(), "\n";
$f->setPattern('yyyy-MM-dd');
echo 'pattern_rt=', $f->getPattern(), "\n";
echo 'format0=', $f->format(0), "\n";
$f->setCalendar(IntlDateFormatter::TRADITIONAL);
echo 'cal_trad=', $f->getCalendar(), "\n";
$f->setCalendar(IntlDateFormatter::GREGORIAN);
echo 'cal_greg=', $f->getCalendar(), "\n";
$cal = IntlCalendar::createInstance('America/New_York', 'en_US');
$f->setCalendar($cal);
echo 'cal_from_obj=', var_export($f->getCalendar(), true), "\n";
echo 'tz_from_cal=', $f->getTimeZoneId(), "\n";
$co = $f->getCalendarObject();
echo 'calobj=', (is_object($co) ? get_class($co) : 'no'), "\n";
?>
--EXPECT--
locale=en_US
dateType=3
timeType=-1
calendar=1
tzid=UTC
lenient=1
lenient2=0
style_pattern=M/d/yy
pattern_rt=yyyy-MM-dd
format0=1970-01-01
cal_trad=0
cal_greg=1
cal_from_obj=false
tz_from_cal=America/New_York
calobj=IntlCalendar
