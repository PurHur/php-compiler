--TEST--
IntlBreakIterator::DONE ClassConstFetch seeds for thin AOT (#35401)
--FILE--
<?php
echo 'DONE=', IntlBreakIterator::DONE, "\n";
echo 'WORD_NONE=', IntlBreakIterator::WORD_NONE, "\n";
echo 'WORD_LETTER=', IntlBreakIterator::WORD_LETTER, "\n";
--EXPECT--
DONE=-1
WORD_NONE=0
WORD_LETTER=200
