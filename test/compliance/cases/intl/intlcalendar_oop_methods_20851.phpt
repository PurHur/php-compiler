--TEST--
IntlCalendar before/after/setDate/bounds/weekend/wall-time (#20851)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
$a = IntlCalendar::createInstance('UTC', 'en_US');
$b = IntlCalendar::createInstance('UTC', 'en_US');
$a->setTime(1705320000000.0); // 2024-01-15 12:00:00 UTC
$b->setTime(1705406400000.0); // 2024-01-16 12:00:00 UTC

echo 'before=', (int) $a->before($b), "\n";
echo 'after=', (int) $b->after($a), "\n";
echo 'not_before=', (int) $b->before($a), "\n";

$a->setDate(2024, IntlCalendar::FEBRUARY, 29);
echo 'setDate=', $a->get(IntlCalendar::FIELD_YEAR), '-',
    $a->get(IntlCalendar::FIELD_MONTH) + 1, '-',
    $a->get(IntlCalendar::FIELD_DAY_OF_MONTH), "\n";

echo 'max_dom=', $a->getMaximum(IntlCalendar::FIELD_DAY_OF_MONTH), "\n";
echo 'min_dom=', $a->getMinimum(IntlCalendar::FIELD_DAY_OF_MONTH), "\n";
echo 'actual_max_feb=', $a->getActualMaximum(IntlCalendar::FIELD_DAY_OF_MONTH), "\n";

$a->setTime(1705320000000.0); // Monday 2024-01-15
echo 'isWeekend_mon=', (int) $a->isWeekend(), "\n";
echo 'isWeekend_sat=', (int) $a->isWeekend(1705795200000.0), "\n"; // 2024-01-21 Sun? use known Sat
// 1705795200000 = 2024-01-21 00:00:00 UTC = Sunday
echo 'dow_type_sat=', $a->getDayOfWeekType(IntlCalendar::DOW_SATURDAY), "\n";
echo 'dow_type_mon=', $a->getDayOfWeekType(IntlCalendar::DOW_MONDAY), "\n";

$c = IntlCalendar::createInstance('America/New_York', 'en_US');
echo 'equiv_same_tz=', (int) $a->isEquivalentTo($b), "\n";
echo 'equiv_diff_tz=', (int) $a->isEquivalentTo($c), "\n";

$a->setTimeZone('Europe/Berlin');
$tz = $a->getTimeZone();
echo 'setTimeZone=', $tz->getID(), "\n";

$a->setRepeatedWallTimeOption(IntlCalendar::WALLTIME_FIRST);
echo 'repeated=', $a->getRepeatedWallTimeOption(), "\n";
$a->setSkippedWallTimeOption(IntlCalendar::WALLTIME_NEXT_VALID);
echo 'skipped=', $a->getSkippedWallTimeOption(), "\n";

echo 'err_code=', $a->getErrorCode(), "\n";
echo 'err_msg=', $a->getErrorMessage(), "\n";

foreach (['before','after','setDate','setTimeZone','getMaximum','getMinimum','getActualMaximum','getActualMinimum','isWeekend','isEquivalentTo','getDayOfWeekType','getErrorCode','getErrorMessage','getRepeatedWallTimeOption','setSkippedWallTimeOption'] as $name) {
    echo 'exists_', $name, '=', method_exists($a, $name) ? 'yes' : 'no', "\n";
}
?>
--EXPECT--
before=1
after=1
not_before=0
setDate=2024-2-29
max_dom=31
min_dom=1
actual_max_feb=29
isWeekend_mon=0
isWeekend_sat=1
dow_type_sat=1
dow_type_mon=0
equiv_same_tz=1
equiv_diff_tz=0
setTimeZone=Europe/Berlin
repeated=1
skipped=2
err_code=0
err_msg=U_ZERO_ERROR
exists_before=yes
exists_after=yes
exists_setDate=yes
exists_setTimeZone=yes
exists_getMaximum=yes
exists_getMinimum=yes
exists_getActualMaximum=yes
exists_getActualMinimum=yes
exists_isWeekend=yes
exists_isEquivalentTo=yes
exists_getDayOfWeekType=yes
exists_getErrorCode=yes
exists_getErrorMessage=yes
exists_getRepeatedWallTimeOption=yes
exists_setSkippedWallTimeOption=yes
