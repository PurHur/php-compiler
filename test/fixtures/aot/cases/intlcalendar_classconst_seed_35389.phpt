--TEST--
IntlCalendar::FIELD_YEAR ClassConstFetch seeds for thin AOT (#35389)
--FILE--
<?php
echo 'FIELD_YEAR=', IntlCalendar::FIELD_YEAR, "\n";
echo 'FIELD_MONTH=', IntlCalendar::FIELD_MONTH, "\n";
echo 'DOW_SUNDAY=', IntlCalendar::DOW_SUNDAY, "\n";
--EXPECT--
FIELD_YEAR=1
FIELD_MONTH=2
DOW_SUNDAY=1
