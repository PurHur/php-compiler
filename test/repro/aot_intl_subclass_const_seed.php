<?php
// #35422 — ClassConstFetch must seed intl subclasses for thin AOT (peer #35389 / #35401).
echo 'GREG_FIELD_YEAR=', IntlGregorianCalendar::FIELD_YEAR, "\n";
echo 'RB_DONE=', IntlRuleBasedBreakIterator::DONE, "\n";
echo 'CP_DONE=', IntlCodePointBreakIterator::DONE, "\n";
echo 'PARTS_KEY_SEQUENTIAL=', IntlPartsIterator::KEY_SEQUENTIAL, "\n";
