--TEST--
AOT: intl subclass ClassConstFetch seeds (#35422)
--FILE--
<?php
echo 'GREG_FIELD_YEAR=', IntlGregorianCalendar::FIELD_YEAR, "\n";
echo 'RB_DONE=', IntlRuleBasedBreakIterator::DONE, "\n";
echo 'CP_DONE=', IntlCodePointBreakIterator::DONE, "\n";
echo 'PARTS_KEY_SEQUENTIAL=', IntlPartsIterator::KEY_SEQUENTIAL, "\n";
--EXPECT--
GREG_FIELD_YEAR=1
RB_DONE=-1
CP_DONE=-1
PARTS_KEY_SEQUENTIAL=0
