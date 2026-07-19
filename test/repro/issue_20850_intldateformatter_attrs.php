<?php
/** Repro #20850 — IntlDateFormatter OOP attr surface (setPattern/getLocale/…). */
$f = IntlDateFormatter::create('en_US', IntlDateFormatter::SHORT, IntlDateFormatter::NONE, 'UTC');
echo 'format0=', $f->format(0), "\n";
echo 'getPattern=', (method_exists($f, 'getPattern') ? 'yes' : 'no'), "\n";
foreach ([
    'setPattern', 'getLocale', 'getDateType', 'getTimeType',
    'isLenient', 'setLenient', 'getCalendar', 'setCalendar',
    'getTimeZoneId', 'getCalendarObject',
] as $m) {
    echo $m, '=', (method_exists($f, $m) ? 'yes' : 'no'), "\n";
}
$f->setPattern('yyyy-MM-dd');
echo 'pattern_rt=', $f->getPattern(), ' format=', $f->format(0), "\n";
echo 'locale=', $f->getLocale(), "\n";
echo 'dateType=', $f->getDateType(), "\n";
echo 'timeType=', $f->getTimeType(), "\n";
echo 'calendar=', $f->getCalendar(), "\n";
echo 'tzid=', $f->getTimeZoneId(), "\n";
echo 'lenient=', (int) $f->isLenient(), "\n";
$f->setLenient(false);
echo 'lenient2=', (int) $f->isLenient(), "\n";
$co = $f->getCalendarObject();
echo 'calobj=', (is_object($co) ? get_class($co) : 'no'), "\n";
