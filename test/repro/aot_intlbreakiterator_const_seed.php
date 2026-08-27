<?php
// #35401 — ClassConstFetch must seed IntlBreakIterator::* for thin AOT (peer #35397).
echo 'DONE=', IntlBreakIterator::DONE, "\n";
echo 'WORD_NONE=', IntlBreakIterator::WORD_NONE, "\n";
echo 'WORD_LETTER=', IntlBreakIterator::WORD_LETTER, "\n";
