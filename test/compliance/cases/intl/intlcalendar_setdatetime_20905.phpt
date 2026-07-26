--TEST--
IntlCalendar::setDateTime() sets date+time; null/omit second → 0 (#20905)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
$c = IntlCalendar::createInstance('UTC', 'en_US');

$c->setTime(0.0);
$c->setDateTime(2024, IntlCalendar::JUNE, 15, 14, 30, 45);
echo 'full_date=', $c->get(IntlCalendar::FIELD_YEAR), '-',
    $c->get(IntlCalendar::FIELD_MONTH) + 1, '-',
    $c->get(IntlCalendar::FIELD_DAY_OF_MONTH), "\n";
echo 'full_time=', $c->get(IntlCalendar::FIELD_HOUR_OF_DAY), ':',
    $c->get(IntlCalendar::FIELD_MINUTE), ':',
    $c->get(IntlCalendar::FIELD_SECOND), "\n";

$c->set(IntlCalendar::FIELD_SECOND, 59);
$c->setDateTime(2024, IntlCalendar::JUNE, 15, 14, 30);
echo 'omit_s=', $c->get(IntlCalendar::FIELD_SECOND), ' H=', $c->get(IntlCalendar::FIELD_HOUR_OF_DAY), "\n";

$c->set(IntlCalendar::FIELD_SECOND, 59);
$c->setDateTime(2024, IntlCalendar::JUNE, 15, 14, 30, null);
echo 'null_s=', $c->get(IntlCalendar::FIELD_SECOND), "\n";

echo 'exists=', method_exists($c, 'setDateTime') ? 'yes' : 'no', "\n";
?>
--EXPECT--
full_date=2024-6-15
full_time=14:30:45
omit_s=0 H=14
null_s=0
exists=yes
