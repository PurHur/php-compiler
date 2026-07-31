<?php

declare(strict_types=1);

/**
 * #25788 — IntlCalendar::setTime/getTime must round-trip fractional UDate
 * (php-src calendar_methods.cpp / ICU UDate).
 */
$cal = IntlCalendar::createInstance('UTC');
$cal->setTime(123.456);
echo 'getTime=', var_export($cal->getTime(), true), "\n";
echo 'ms=', var_export($cal->get(IntlCalendar::FIELD_MILLISECOND), true), "\n";
$cal->setTime(1000.999);
echo 'getTime2=', var_export($cal->getTime(), true), "\n";
echo 'ms2=', var_export($cal->get(IntlCalendar::FIELD_MILLISECOND), true), "\n";
$cal->setTime(1500.0);
echo 'getTime3=', var_export($cal->getTime(), true), "\n";
echo 'ms3=', var_export($cal->get(IntlCalendar::FIELD_MILLISECOND), true), "\n";
$cal->setTime(-123.456);
echo 'getTimeNeg=', var_export($cal->getTime(), true), "\n";
echo 'msNeg=', var_export($cal->get(IntlCalendar::FIELD_MILLISECOND), true), "\n";
intlcal_set_time($cal, 42.125);
echo 'proc=', var_export(intlcal_get_time($cal), true), "\n";
echo 'procMs=', var_export(intlcal_get($cal, IntlCalendar::FIELD_MILLISECOND), true), "\n";
