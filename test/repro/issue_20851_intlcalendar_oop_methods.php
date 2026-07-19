<?php

declare(strict_types=1);

$c = IntlCalendar::createInstance('UTC', 'en_US');
foreach (['before', 'after', 'setDate', 'setTimeZone', 'getMaximum', 'getMinimum', 'getActualMaximum', 'getActualMinimum', 'isWeekend', 'isEquivalentTo', 'getDayOfWeekType', 'getErrorCode', 'getErrorMessage', 'getRepeatedWallTimeOption', 'setSkippedWallTimeOption'] as $m) {
    echo $m . '=' . (method_exists($c, $m) ? 'yes' : 'no') . "\n";
}
$c->setTime(1705320000000.0);
$other = IntlCalendar::createInstance('UTC', 'en_US');
$other->setTime(1705406400000.0);
echo 'before=' . (int) $c->before($other) . "\n";
$c->setDate(2024, IntlCalendar::JANUARY, 20);
echo 'setDate_dom=' . $c->get(IntlCalendar::FIELD_DAY_OF_MONTH) . "\n";
echo 'max_month=' . $c->getMaximum(IntlCalendar::FIELD_MONTH) . "\n";
echo 'isWeekend=' . (int) $c->isWeekend(1705795200000.0) . "\n";
