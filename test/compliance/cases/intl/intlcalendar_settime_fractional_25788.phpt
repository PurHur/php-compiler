--TEST--
IntlCalendar::setTime/getTime preserve sub-millisecond float UDate (#25788)
--SKIPIF--
<?php
if (!extension_loaded('intl') || !class_exists('IntlCalendar', false)) {
    die('skip host php-intl required for IntlCalendar advertisement');
}
?>
--FILE--
<?php
$cal = IntlCalendar::createInstance('UTC');
$cal->setTime(123.456);
echo 'getTime=', var_export($cal->getTime(), true), "\n";
echo 'ms=', var_export($cal->get(IntlCalendar::FIELD_MILLISECOND), true), "\n";
$cal->setTime(1000.999);
echo 'getTime2=', var_export($cal->getTime(), true), "\n";
echo 'ms2=', var_export($cal->get(IntlCalendar::FIELD_MILLISECOND), true), "\n";
$cal->setTime(-123.456);
echo 'getTimeNeg=', var_export($cal->getTime(), true), "\n";
echo 'msNeg=', var_export($cal->get(IntlCalendar::FIELD_MILLISECOND), true), "\n";
?>
--EXPECT--
getTime=123.456
ms=123
getTime2=1000.999
ms2=0
getTimeNeg=-123.456
msNeg=876
